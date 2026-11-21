<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatbotService
{
    public function __construct(private ?GraphSearch $search = null) {}

    public function askMovieBot(string $userQuery): string
    {
        $userQuery = trim(preg_replace('/\s+/u', ' ', $userQuery) ?? '');
        if ($userQuery === '') {
            return 'Tulis pertanyaan tentang film, orang, genre, atau rekomendasi.';
        }
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            return $this->semanticFallback($userQuery);
        }

        $model = config('services.gemini.model');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $context = $this->semanticContext($userQuery);
        $history = $this->history((string) session()->getId());
        $prompt = "Kamu adalah asisten film CINÉLOG. Jawab dalam bahasa Indonesia dengan ringkas dan jujur.\n"
            . "Gunakan konteks knowledge graph di bawah ini jika relevan. Jangan mengarang fakta yang tidak ada di konteks.\n"
            . "KONTEKS RDF/FUSEKI:\n{$context}\n"
            . "RIWAYAT SINGKAT:\n{$history}\n"
            . "PERTANYAAN PENGGUNA:\n{$userQuery}";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(20)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini chatbot request failed.', ['status' => $response->status()]);

                return $this->semanticFallback($userQuery);
            }

            $reply = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($reply) || trim($reply) === '') {
                Log::warning('Gemini chatbot returned no reply.');

                return $this->semanticFallback($userQuery);
            }

            $this->saveHistory((string) session()->getId(), $userQuery, $reply);

            return $reply;
        } catch (ConnectionException $exception) {
            Log::warning('Gemini chatbot connection failed.', ['message' => $exception->getMessage()]);

            return $this->semanticFallback($userQuery);
        }
    }

    private function semanticFallback(string $query): string
    {
        try {
            $result = ($this->search ?? app(GraphSearch::class))->search($query);
            $films = array_slice($result['films'] ?? [], 0, 6);
            $people = array_slice($result['people'] ?? [], 0, 4);
            $genres = array_slice($result['genres'] ?? [], 0, 3);

            if ($films !== []) {
                $items = array_map(fn (array $film) => $film['title'], $films);
                return "Berdasarkan knowledge graph CINÉLOG, hasil yang relevan adalah:\n• " . implode("\n• ", $items)
                    . "\n\nBuka detail film di katalog untuk melihat informasi lengkapnya.";
            }
            if ($people !== []) {
                return 'Saya menemukan orang berikut di knowledge graph CINÉLOG: '.implode(', ', array_column($people, 'name')).'.';
            }
            if ($genres !== []) {
                return 'Genre yang cocok di knowledge graph CINÉLOG: '.implode(', ', array_column($genres, 'label')).'.';
            }
        } catch (\Throwable $e) {
            Log::warning('Semantic chatbot fallback failed.', ['message' => $e->getMessage()]);
        }

        return 'Saya belum menemukan data yang cocok di knowledge graph CINÉLOG. Coba sebutkan judul film, nama aktor, atau genre yang lebih spesifik.';
    }

    private function semanticContext(string $query): string
    {
        try {
            $result = ($this->search ?? app(GraphSearch::class))->search($query);
            $films = array_slice($result['films'] ?? [], 0, 8);
            $people = array_slice($result['people'] ?? [], 0, 5);
            $genres = array_slice($result['genres'] ?? [], 0, 5);
            $lines = [];
            foreach ($films as $film) {
                $lines[] = 'Film: '.$film['title'].' | URL: '.route('film.show', $film['slug'], false);
            }
            foreach ($people as $person) {
                $lines[] = 'Orang: '.$person['name'].' | film terkait: '.$person['films'];
            }
            foreach ($genres as $genre) {
                $lines[] = 'Genre: '.$genre['label'].' | film: '.$genre['films'];
            }

            return $lines === [] ? 'Tidak ada hasil semantik untuk pertanyaan ini.' : implode("\n", $lines);
        } catch (\Throwable $e) {
            report($e);
            return 'Konteks Fuseki sementara tidak tersedia.';
        }
    }

    private function history(string $sessionId): string
    {
        if (! class_exists(\MongoDB\Client::class) || ! config('services.mongodb.uri')) {
            return 'Tidak ada riwayat tersimpan.';
        }
        try {
            $client = new \MongoDB\Client(config('services.mongodb.uri'));
            $rows = $client->selectDatabase(config('services.mongodb.database'))->selectCollection('chat_messages')
                ->find(['session_id' => $sessionId], ['sort' => ['created_at' => -1], 'limit' => 6])->toArray();
            return implode("\n", array_reverse(array_map(fn ($row) => ($row['user'] ?? '').' => '.($row['assistant'] ?? ''), $rows))) ?: 'Tidak ada riwayat tersimpan.';
        } catch (\Throwable $e) {
            Log::warning('MongoDB chat history read failed.', ['message' => $e->getMessage()]);
            return 'Tidak ada riwayat tersimpan.';
        }
    }

    private function saveHistory(string $sessionId, string $user, string $assistant): void
    {
        if (! class_exists(\MongoDB\Client::class) || ! config('services.mongodb.uri')) {
            return;
        }
        try {
            $client = new \MongoDB\Client(config('services.mongodb.uri'));
            $client->selectDatabase(config('services.mongodb.database'))->selectCollection('chat_messages')->insertOne([
                'session_id' => $sessionId,
                'user' => Str::limit($user, 1000, ''),
                'assistant' => Str::limit($assistant, 4000, ''),
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MongoDB chat history write failed.', ['message' => $e->getMessage()]);
        }
    }
}

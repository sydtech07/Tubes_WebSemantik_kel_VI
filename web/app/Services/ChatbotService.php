<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    public function askMovieBot(string $userQuery): string
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            return 'Chatbot belum dikonfigurasi. Tambahkan GEMINI_API_KEY ke file .env.';
        }

        $model = config('services.gemini.model');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(20)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Kamu adalah asisten film CINÉLOG. Jawab dengan ramah dan informatif dalam bahasa Indonesia.\nPertanyaan pengguna: {$userQuery}"],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini chatbot request failed.', ['status' => $response->status()]);

                return 'Maaf, layanan chatbot sedang bermasalah. Coba lagi nanti.';
            }

            $reply = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($reply) || trim($reply) === '') {
                Log::warning('Gemini chatbot returned no reply.');

                return 'Maaf, chatbot belum bisa menjawab pertanyaan itu.';
            }

            return $reply;
        } catch (ConnectionException $exception) {
            Log::warning('Gemini chatbot connection failed.', ['message' => $exception->getMessage()]);

            return 'Maaf, layanan chatbot tidak dapat dihubungi. Coba lagi nanti.';
        }
    }
}

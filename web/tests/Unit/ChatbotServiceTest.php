<?php

namespace Tests\Unit;

use App\Services\ChatbotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    public function test_it_uses_the_configured_gemini_model_and_returns_the_reply(): void
    {
        Config::set('services.gemini.api_key', 'test-api-key');
        Config::set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Coba tonton film ini.']]]],
                ],
            ]),
        ]);

        $reply = app(ChatbotService::class)->askMovieBot('Rekomendasi film?');

        $this->assertSame('Coba tonton film ini.', $reply);
        Http::assertSent(fn (Request $request) => $request->url() ===
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Rekomendasi film?'));
    }

    public function test_it_returns_a_friendly_message_when_gemini_is_unavailable(): void
    {
        Config::set('services.gemini.api_key', 'test-api-key');
        Config::set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 503),
        ]);

        $reply = app(ChatbotService::class)->askMovieBot('Rekomendasi film?');

        $this->assertSame('Maaf, layanan chatbot sedang bermasalah. Coba lagi nanti.', $reply);
    }
}

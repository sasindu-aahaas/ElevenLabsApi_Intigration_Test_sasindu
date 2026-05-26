<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AahaasChatGpt3vService extends AiAssistentFinalTestService
{
    private const SUPPORTED_VOICES = [
        'alloy',
        'echo',
        'fable',
        'onyx',
        'nova',
        'shimmer',
        'coral',
        'verse',
        'ballad',
        'ash',
        'sage',
        'marin',
        'cedar',
    ];

    private const VOICE_ALIASES = [
        'sol' => 'marin',
        'cove' => 'cedar',
    ];

    public function initializeCustomerProfile(?string $selectedVoice = null): array
    {
        $voiceId = $this->normalizeVoiceSelection($selectedVoice);

        return [
            'package_state' => 'not_started',
            'assistant_voice_id' => $voiceId,
        ];
    }

    public function getSupportedVoices(): array
    {
        return self::SUPPORTED_VOICES;
    }

    public function buildGreeting(string $callId): string
    {
        $variants = [
            'Hello, welcome to Aahaas. I can help with your travel booking today. Where would you like to go?',
            'Good day and welcome to Aahaas. I can help arrange your trip. Which destination do you have in mind?',
            'Hello from Aahaas. I can help with your booking today. What destination would you like us to plan for you?',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildHoldMessage(): string
    {
        $variants = [
            'I am preparing the best option for you now. Please stay with me for a moment.',
            'I am checking the package details for you now. Please hold for a brief moment.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildClosingMessage(): string
    {
        $variants = [
            'Thank you for calling Aahaas. We will send your travel details by WhatsApp shortly.',
            'Thanks for choosing Aahaas. Our team will share the booking details with you very soon on WhatsApp.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildPackageFailureCallbackReply(): string
    {
        $variants = [
            'Our Aahaas package service is busy right now, but I have your requirements and our team will contact you with the best option shortly.',
            'The package system is temporarily busy, but we have your travel requirements and our team will contact you with the best available option very soon.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildPackageDetailsPendingReply(): string
    {
        $variants = [
            'I am still waiting for the Aahaas package result, so I do not want to guess the package details. Let me confirm one more requirement while that is loading.',
            'The Aahaas package details are still loading, so I will not give you guessed package information. Let me confirm one more detail while we wait.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildFinalReport(array $history = [], array $customerProfile = [], array $serviceCategories = []): array
    {
        return parent::buildFinalReport(
            $history,
            $this->applyDefaultTravelAssumptions($customerProfile, $serviceCategories),
            $serviceCategories
        );
    }

    public function generateTurn(array $history = [], array $customerProfile = [], array $serviceCategories = []): array
    {
        $customerProfile = $this->applyDefaultTravelAssumptions($customerProfile, $serviceCategories);
        $conversationLines = [];

        foreach ($history as $message) {
            $role = $message['role'] ?? null;
            $content = trim((string) ($message['content'] ?? ''));

            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $conversationLines[] = strtoupper($role) . ': ' . $content;
        }

        $payload = [
            [
                'role' => 'developer',
                'content' => $this->chatGpt3vSystemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'known_customer_profile' => $customerProfile,
                    'known_service_categories' => $serviceCategories,
                    'known_package_state' => $customerProfile['package_state'] ?? null,
                    'known_package_offer' => $customerProfile['suggested_package'] ?? null,
                    'conversation' => $conversationLines,
                ], JSON_UNESCAPED_SLASHES),
            ],
        ];

        $raw = $this->sendResponsesRequest($payload);
        $decoded = $this->decodeJsonObject($raw);
        $reply = trim((string) ($decoded['reply'] ?? ''));

        if ($reply === '') {
            $reply = 'Please tell me a little more so I can help you properly.';
        }

        return [
            'reply' => $reply,
            'customer_profile' => is_array($decoded['customer_profile'] ?? null) ? $decoded['customer_profile'] : [],
            'service_categories' => array_values(array_filter(
                is_array($decoded['service_categories'] ?? null) ? $decoded['service_categories'] : [],
                fn ($value) => is_string($value) && trim($value) !== ''
            )),
            'should_end' => (bool) ($decoded['should_end'] ?? false),
            'ended_reason' => trim((string) ($decoded['ended_reason'] ?? '')),
            'live_summary' => trim((string) ($decoded['live_summary'] ?? '')),
            'needs_travel_package' => (bool) ($decoded['needs_travel_package'] ?? false),
            'travel_package_prompt' => trim((string) ($decoded['travel_package_prompt'] ?? '')),
            'package_confirmation_status' => trim((string) ($decoded['package_confirmation_status'] ?? '')),
        ];
    }

    public function buildEarlyPackagePrompt(array $customerProfile, string $transcript, array $serviceCategories): string
    {
        return parent::buildEarlyPackagePrompt(
            $this->applyDefaultTravelAssumptions($customerProfile, $serviceCategories),
            $transcript,
            $serviceCategories
        );
    }

    public function shouldWaitForPackage(array $customerProfile, array $serviceCategories): bool
    {
        return parent::shouldWaitForPackage(
            $this->applyDefaultTravelAssumptions($customerProfile, $serviceCategories),
            $serviceCategories
        );
    }

    public function hasEnoughTravelRequirements(array $customerProfile): bool
    {
        return parent::hasEnoughTravelRequirements($this->applyDefaultTravelAssumptions($customerProfile, [
            'Hotel Booking',
            'Flight Booking',
            'Sri Lanka Tour Planning',
            'Transportation',
            'Activities and Experiences',
        ]));
    }

    public function synthesizeSpeechForProfile(string $text, array $customerProfile = [], ?string $instructions = null): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Text is required for speech synthesis.');
        }

        $openAiApiKey = $this->requiredConfigValue('OPENAI_API_KEY');
        $voiceModel = env('OPENAI_VOICE_MODEL', 'gpt-4o-mini-tts');
        $voiceInstructions = $instructions ?: env(
            'OPENAI_RECEPTION_VOICE_INSTRUCTIONS',
            'Speak like a warm Aahaas travel receptionist on a live phone call. Keep the delivery clear, natural, upbeat, and fast.'
        );

        $voiceId = $this->normalizeVoiceSelection((string) ($customerProfile['assistant_voice_id'] ?? ''));
        $response = Http::withToken($openAiApiKey)
            ->timeout(120)
            ->withHeaders([
                'Accept' => 'audio/wav',
                'Content-Type' => 'application/json',
            ])
            ->asJson()
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $voiceModel,
                'voice' => $voiceId,
                'input' => $text,
                'instructions' => $voiceInstructions,
                'response_format' => 'wav',
            ]);

        if ($response->failed()) {
            $status = $response->status() ?: 500;
            $message = $this->extractVoiceErrorMessage($response);
            throw new RuntimeException($message !== '' ? $message : 'OpenAI speech generation failed.', $status);
        }

        return [
            'body' => $response->body(),
            'mime_type' => $response->header('Content-Type', 'audio/wav'),
            'voice' => $voiceId,
            'voice_label' => $voiceId,
        ];
    }

    public function getVoiceLabel(array $customerProfile = []): string
    {
        return $this->normalizeVoiceSelection((string) ($customerProfile['assistant_voice_id'] ?? ''));
    }

    private function chatGpt3vSystemPrompt(): string
    {
        return <<<'PROMPT'
You are Aahaas AI, a warm and natural phone receptionist for Aahaas.

CALL STYLE:
- Sound natural, calm, and helpful.
- Keep replies short for voice playback: one or two brief sentences.
- Ask exactly one clear follow-up question each turn.
- Do not say robotic phrases like "I noted that down" or "I recorded that."

DEFAULT TRAVEL ASSUMPTIONS:
- If the caller wants a hotel or travel booking but does not clearly specify traveler count, assume 2 travelers.
- If hotel class is not clearly given, assume a 3-star hotel.
- If duration is not clearly given, assume 3 days.
- If start timing is not clearly given, assume the trip starts 7 days from today.
- Use these defaults without repeatedly asking for them, unless the caller later changes them.

FLOW:
1. Understand the main travel or support request.
2. Collect missing contact details naturally: full name, contact number, email address, and current living country.
3. For travel requests, identify the destination first, then gather only the most important missing detail each turn.
4. When enough travel information exists, prepare a package prompt.
5. Present package options naturally only when known_package_offer is available from the Aahaas API.
6. If known_package_offer is missing or the Aahaas API is unavailable, never invent package names, hotels, prices, room types, or itineraries. Continue collecting the customer's full requirements and explain that the team will contact them with the best option.
7. End only after the caller confirms they are done.

PACKAGE SAFETY:
- Only package details from known_package_offer may be spoken as a real package recommendation.
- Never guess or fabricate package content.
- If the package system is still loading, say you are waiting for the Aahaas package result and continue collecting requirements.
- If the package system fails, apologize briefly, collect any missing requirements, and say the Aahaas team will contact the customer with the best available option.

Always return strict JSON with exactly these keys: reply, customer_profile, service_categories, should_end, ended_reason, live_summary, needs_travel_package, travel_package_prompt, package_confirmation_status.
PROMPT;
    }

    private function applyDefaultTravelAssumptions(array $customerProfile, array $serviceCategories): array
    {
        if (! $this->isTravelRelated($serviceCategories)) {
            return $customerProfile;
        }

        $assumptions = is_array($customerProfile['default_assumptions_applied'] ?? null)
            ? $customerProfile['default_assumptions_applied']
            : [];

        if (trim((string) ($customerProfile['traveler_count'] ?? $customerProfile['number_of_travelers'] ?? '')) === '') {
            $customerProfile['traveler_count'] = '2';
            $customerProfile['number_of_travelers'] = '2';
            $assumptions[] = 'traveler_count_default_2';
        }

        if (trim((string) ($customerProfile['stay_length'] ?? $customerProfile['number_of_days'] ?? '')) === '') {
            $customerProfile['stay_length'] = '3 days';
            $customerProfile['number_of_days'] = '3 days';
            $assumptions[] = 'duration_default_3_days';
        }

        if (trim((string) ($customerProfile['hotel_rating_preference'] ?? $customerProfile['hotel_category'] ?? '')) === '') {
            $customerProfile['hotel_rating_preference'] = '3-star hotel';
            $customerProfile['hotel_category'] = '3-star hotel';
            $assumptions[] = 'hotel_rating_default_3_star';
        }

        if (trim((string) ($customerProfile['travel_date_range'] ?? $customerProfile['planned_travel_date_range'] ?? '')) === '') {
            $startDate = Carbon::now()->addDays(7)->startOfDay();
            $endDate = $startDate->copy()->addDays(3);
            $dateRange = sprintf(
                'Starting on %s for 3 days, ending on %s',
                $startDate->toFormattedDateString(),
                $endDate->toFormattedDateString()
            );
            $customerProfile['travel_date_range'] = $dateRange;
            $customerProfile['planned_travel_date_range'] = $dateRange;
            $customerProfile['start_date_preference'] = $startDate->toDateString();
            $assumptions[] = 'start_date_default_plus_7_days';
        }

        $customerProfile['default_assumptions_applied'] = array_values(array_unique($assumptions));

        return $customerProfile;
    }

    private function extractVoiceErrorMessage(Response $response): string
    {
        $details = $response->json('error.message');

        if (is_string($details) && trim($details) !== '') {
            return trim($details);
        }

        $body = $response->body();

        return is_string($body) ? trim($body) : '';
    }

    private function normalizeVoiceSelection(?string $selectedVoice): string
    {
        $voice = strtolower(trim((string) $selectedVoice));

        if (isset(self::VOICE_ALIASES[$voice])) {
            return self::VOICE_ALIASES[$voice];
        }

        if (in_array($voice, self::SUPPORTED_VOICES, true)) {
            return $voice;
        }

        return 'marin';
    }
}

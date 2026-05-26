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

    public function initializeCustomerProfile(?string $selectedVoice = null, ?float $speechSpeed = null): array
    {
        $voiceId = $this->normalizeVoiceSelection($selectedVoice);

        return [
            'package_state' => 'not_started',
            'assistant_voice_id' => $voiceId,
            'assistant_speech_speed' => $this->normalizeSpeechSpeed($speechSpeed),
        ];
    }

    public function getSupportedVoices(): array
    {
        return self::SUPPORTED_VOICES;
    }

    public function buildGreeting(string $callId): string
    {
        $variants = [
            'Hello, welcome to Aahaas. Tell me what you want, and I will search the best matching product for you.',
            'Good day and welcome to Aahaas. Tell me what you need, and I will check the best matching Aahaas product for you.',
            'Hello from Aahaas. Tell me what you want, and I will search the best Aahaas option for you.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildHoldMessage(): string
    {
        $variants = [
            'I am searching the best Aahaas product for you now. Please hold for a moment.',
            'I am checking the best matching Aahaas option now. Please stay with me for a moment.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildClosingMessage(): string
    {
        $variants = [
            'Thank you for calling Aahaas. We will send your booking details shortly.',
            'Thanks for choosing Aahaas. Our team will share the booking details with you very soon.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildPackageFailureCallbackReply(): string
    {
        $variants = [
            'Our Aahaas package service is busy right now, but I have your request and our team will contact you with the best option shortly.',
            'The package system is temporarily busy, but I have your travel request and our team will contact you with the best available option very soon.',
        ];

        return $variants[array_rand($variants)];
    }

    public function buildPackageDetailsPendingReply(): string
    {
        $variants = [
            'I am still waiting for the Aahaas package result, so I do not want to guess the package details. Please hold while I check it.',
            'The Aahaas package details are still loading, so I will not guess the package information. Please hold while I check it.',
        ];

        return $variants[array_rand($variants)];
    }

    public function looksLikeSearchableRequest(string $text): bool
    {
        $normalized = strtolower(trim($text));

        if ($normalized === '' || mb_strlen($normalized) < 4) {
            return false;
        }

        return true;
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
        $customerProfile = $this->applyDefaultTravelAssumptions($customerProfile, $serviceCategories);
        $lookupStatus = trim((string) ($customerProfile['package_lookup_status'] ?? ''));
        $storedPrompt = trim((string) ($customerProfile['travel_package_prompt'] ?? ''));

        return ($this->isTravelRelated($serviceCategories) || $storedPrompt !== '')
            && in_array($lookupStatus, ['queued', 'pending'], true)
            && $storedPrompt !== '';
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
            'Speak like a warm Aahaas travel receptionist on a live phone call. Keep the delivery clear, natural, upbeat, and fast. Use short spoken phrasing.'
        );
        $speechSpeed = $this->normalizeSpeechSpeed($customerProfile['assistant_speech_speed'] ?? null);

        $voiceId = $this->normalizeVoiceSelection((string) ($customerProfile['assistant_voice_id'] ?? ''));
        $response = Http::withToken($openAiApiKey)
            ->timeout(120)
            ->withHeaders([
                'Accept' => 'audio/mpeg',
                'Content-Type' => 'application/json',
            ])
            ->asJson()
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $voiceModel,
                'voice' => $voiceId,
                'input' => $text,
                'instructions' => $voiceInstructions,
                'response_format' => 'wav',
                'speed' => $speechSpeed,
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
- Keep every spoken reply low-latency and concise.

DEFAULT TRAVEL ASSUMPTIONS:
- If the caller wants a hotel or travel booking but does not clearly specify traveler count, assume 2 travelers.
- If hotel class is not clearly given, assume a 3-star hotel.
- If duration is not clearly given, assume 3 days.
- If start timing is not clearly given, assume the trip starts 7 days from today.
- If activities are not clearly given, assume standard sightseeing suitable for the destination.
- Use these defaults silently without asking extra questions about them, unless the caller later asks to change them.

FLOW:
1. Start with one simple opening only. Do not begin with many questions.
2. If the customer tells you what they want, use that request immediately to check the Aahaas API for the best matching package or product.
3. If the customer shares a name, store it and use it naturally. If they do not share a name, do not push for it.
4. Do not ask unnecessary preference questions like relaxed sightseeing, active plan, or similar extra discovery questions. Just search the best fit first.
5. If the request is travel-related, do not ask unnecessary planning questions like traveler count, number of days, when it starts, activities, or hotel class if the customer did not mention them. Use the defaults instead.
6. Present package or product options naturally only when known_package_offer is available from the Aahaas API.
7. After reading the package or booking summary, ask one simple question only: does anything need to change.
7. If the customer wants changes, update only the parts they asked to change. Do not ask unrelated questions.
8. Only after the customer confirms the booking summary, ask for WhatsApp and email details.
9. If known_package_offer is missing or the Aahaas API is unavailable, never invent package names, hotels, prices, room types, itineraries, or product details. Simply say the Aahaas service is busy, keep the customer request as given, and say the Aahaas team will contact them with the best option.
10. End only after the customer confirms they are done.

PACKAGE SAFETY:
- Only package details from known_package_offer may be spoken as a real package recommendation.
- Never guess or fabricate package content.
- If the package system is still loading, say you are waiting for the Aahaas package result and continue collecting requirements.
- If the package system fails, apologize briefly, collect any missing requirements, and say the Aahaas team will contact the customer with the best available option.

CONTACT TIMING:
- Do not ask for contact details at the beginning of trip planning.
- Ask for WhatsApp and email only after the travel plan summary is confirmed or when the package API is unavailable and the team needs to follow up.
- Do not ask for traveler count, duration, hotel class, or start date if the customer did not explicitly mention them and the defaults are sufficient.
- If the customer gives their name naturally, use it. If not, continue without making it a blocker.

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

        if (trim((string) ($customerProfile['activities_preference'] ?? $customerProfile['activity_preference'] ?? '')) === '') {
            $customerProfile['activities_preference'] = 'standard sightseeing';
            $customerProfile['activity_preference'] = 'standard sightseeing';
            $assumptions[] = 'activities_default_standard_sightseeing';
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

    private function normalizeSpeechSpeed(mixed $speed): float
    {
        $normalized = is_numeric($speed) ? (float) $speed : 1.0;

        if ($normalized < 0.25) {
            return 0.25;
        }

        if ($normalized > 2.0) {
            return 2.0;
        }

        return round($normalized, 2);
    }
}

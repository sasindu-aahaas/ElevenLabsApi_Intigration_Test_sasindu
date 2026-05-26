<?php

namespace App\Http\Controllers;

use App\Models\ServiceCall;
use App\Services\AahaasAssistentV01Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AahaasAssistentV01EndController extends Controller
{
    public function __invoke(Request $request, AahaasAssistentV01Service $service): JsonResponse
    {
        set_time_limit(300);

        $validated = $request->validate([
            'call_id'      => ['required', 'string', 'exists:service_calls,call_id'],
            'ended_reason' => ['nullable', 'string'],
            'voice_name'   => ['nullable', 'string'],
            'voice_speed'  => ['nullable', 'numeric', 'min:0.25', 'max:4.0'],
        ]);

        $voiceName  = trim((string) ($validated['voice_name'] ?? 'coral')) ?: 'coral';
        $voiceSpeed = (float) ($validated['voice_speed'] ?? 1.0);
        $service->setVoiceConfig($voiceName, $voiceSpeed);

        $call              = ServiceCall::query()->where('call_id', $validated['call_id'])->firstOrFail();
        $history           = is_array($call->conversation_history) ? $call->conversation_history : [];
        $customerProfile   = is_array($call->customer_profile) ? $call->customer_profile : [];
        $serviceCategories = is_array($call->service_categories) ? $call->service_categories : [];

        try {
            $report       = $service->buildFinalReport($history, $customerProfile, $serviceCategories);
            $endedReason  = trim((string) ($validated['ended_reason'] ?? 'completed'));
            $closingMessage = $endedReason === 'package_api_unavailable'
                ? $service->buildPackageFailureCallbackReply()
                : $service->buildClosingMessage();
            $closingAudio = $service->synthesizeSpeech($closingMessage);

            // Merge report profile back so quotation has the most complete data
            $finalProfile = array_filter(
                array_merge($customerProfile, $report['customer_profile'] ?: []),
                fn ($v) => $v !== null && $v !== ''
            );
            $finalCategories = array_values(array_unique(array_merge(
                $serviceCategories,
                $report['service_categories'] ?: []
            )));

            // Auto-send quotation when all three contact details are present
            $quotationResult = ['api_sent' => false, 'email_sent' => false, 'error' => null];
            if ($service->hasQuotationContacts($finalProfile)) {
                $quotationResult = $service->sendQuotation(
                    $call->call_id,
                    $finalProfile,
                    $report,
                    $finalCategories
                );
            }

            $call->forceFill([
                'status'             => 'completed',
                'final_report'       => json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'latest_report'      => $report['summary'] ?? $call->latest_report,
                'ended_at'           => now(),
                'ended_reason'       => $endedReason,
                'customer_profile'   => $finalProfile,
                'service_categories' => $finalCategories,
            ])->save();

            return response()->json([
                'call_id'          => $call->call_id,
                'status'           => $call->status,
                'report'           => $report,
                'closing_message'  => $closingMessage,
                'audio_base64'     => base64_encode($closingAudio['body']),
                'audio_mime_type'  => $closingAudio['mime_type'],
                'quotation_sent'   => $quotationResult['api_sent'] || $quotationResult['email_sent'],
                'quotation_api'    => $quotationResult['api_sent'],
                'quotation_email'  => $quotationResult['email_sent'],
                'quotation_wa_id'  => $quotationResult['wa_id'] ?? '',
                'quotation_error'  => $quotationResult['error'],
            ]);
        } catch (Throwable $throwable) {
            $status = $throwable->getCode();

            return response()->json([
                'message' => $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Aahaas Assistent V01 call could not be completed.',
            ], is_int($status) && $status >= 400 && $status < 600 ? $status : 500);
        }
    }
}

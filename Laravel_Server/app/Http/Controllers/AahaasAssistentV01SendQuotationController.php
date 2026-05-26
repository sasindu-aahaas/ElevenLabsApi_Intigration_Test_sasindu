<?php

namespace App\Http\Controllers;

use App\Models\ServiceCall;
use App\Services\AahaasAssistentV01Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AahaasAssistentV01SendQuotationController extends Controller
{
    public function __invoke(Request $request, AahaasAssistentV01Service $service): JsonResponse
    {
        set_time_limit(300);

        $validated = $request->validate([
            'call_id' => ['required', 'string', 'exists:service_calls,call_id'],
        ]);

        $call              = ServiceCall::query()->where('call_id', $validated['call_id'])->firstOrFail();
        $customerProfile   = is_array($call->customer_profile) ? $call->customer_profile : [];
        $serviceCategories = is_array($call->service_categories) ? $call->service_categories : [];

        // Build report from stored data
        $reportJson = $call->final_report;
        $report     = [];

        if (is_string($reportJson) && $reportJson !== '') {
            $decoded = json_decode($reportJson, true);
            if (is_array($decoded)) {
                $report = $decoded;
            }
        }

        if (empty($report)) {
            $report = [
                'summary'            => is_string($call->latest_report) ? $call->latest_report : '',
                'products_needed'    => [],
                'service_categories' => $serviceCategories,
                'customer_profile'   => $customerProfile,
                'follow_up_actions'  => [],
            ];
        }

        try {
            if (! $service->hasQuotationContacts($customerProfile)) {
                return response()->json([
                    'call_id' => $call->call_id,
                    'sent'    => false,
                    'message' => 'Contact details are incomplete (need full name, country, and contact number).',
                ], 422);
            }

            $result = $service->sendQuotation($call->call_id, $customerProfile, $report, $serviceCategories);

            return response()->json([
                'call_id'         => $call->call_id,
                'sent'            => $result['api_sent'] || $result['email_sent'],
                'quotation_api'   => $result['api_sent'],
                'quotation_email' => $result['email_sent'],
                'error'           => $result['error'],
            ]);
        } catch (Throwable $throwable) {
            $status = $throwable->getCode();

            return response()->json([
                'message' => $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Quotation could not be sent.',
            ], is_int($status) && $status >= 400 && $status < 600 ? $status : 500);
        }
    }
}

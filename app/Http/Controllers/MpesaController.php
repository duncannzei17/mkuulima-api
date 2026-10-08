<?php

namespace App\Http\Controllers;

use App\Models\MpesaLog;
use App\Models\Transaction;
use App\Models\PendingPayment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * M-Pesa Integration Controller
 * 
 * This controller handles M-Pesa payment integration including:
 * - STK Push (Lipa na M-Pesa Online)
 * - B2C (Business to Customer) payments
 * - C2B (Customer to Business) confirmations
 * - Transaction status queries
 * - Account balance queries
 * 
 * Note: This is a foundational implementation that can be extended
 * with actual M-Pesa Daraja API integration when API credentials are available.
 */
class MpesaController extends Controller
{
    protected $consumerKey;
    protected $consumerSecret;
    protected $passkey;
    protected $shortcode;
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        // M-Pesa configuration (from environment variables)
        $this->consumerKey = config('mpesa.consumer_key');
        $this->consumerSecret = config('mpesa.consumer_secret');
        $this->passkey = config('mpesa.passkey');
        $this->shortcode = config('mpesa.shortcode');
        $this->baseUrl = config('mpesa.base_url', 'https://sandbox.safaricom.co.ke');
    }

    /**
     * STK Push (Lipa na M-Pesa Online)
     * Initiates a payment request to the customer's phone
     */
    public function stkPush(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => ['required', 'string', 'regex:/^(?:\+254|254|0)?[17]\d{8}$/'],
            'amount' => 'required|numeric|min:1|max:150000',
            'account_reference' => 'required|string|max:20',
            'transaction_description' => 'required|string|max:50',
            'transaction_id' => 'nullable|uuid', // Optional link to existing transaction
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // Format phone number
            $phoneNumber = $this->formatPhoneNumber($request->phone_number);

            // Generate timestamp and password
            $timestamp = now()->format('YmdHis');
            $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

            // Create M-Pesa log entry
            $mpesaLog = MpesaLog::createStkPushLog([
                'BusinessShortCode' => $this->shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $request->amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $this->shortcode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => route('mpesa.stk-callback'),
                'AccountReference' => $request->account_reference,
                'TransactionDesc' => $request->transaction_description,
            ]);

            // Link to existing transaction if provided
            if ($request->transaction_id) {
                $transaction = Transaction::find($request->transaction_id);
                if ($transaction) {
                    $mpesaLog->transaction_id = $transaction->id;
                    $mpesaLog->save();
                }
            }

            // In a real implementation, this would call the M-Pesa API
            $response = $this->callMpesaAPI('mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $this->shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $request->amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $this->shortcode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => route('mpesa.stk-callback'),
                'AccountReference' => $request->account_reference,
                'TransactionDesc' => $request->transaction_description,
            ]);

            // Update log with response
            $mpesaLog->response_payload = $response;
            
            if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
                $mpesaLog->status = 'pending';
                $mpesaLog->checkout_request_id = $response['CheckoutRequestID'] ?? null;
                $mpesaLog->merchant_request_id = $response['MerchantRequestID'] ?? null;
                $mpesaLog->response_code = $response['ResponseCode'];
                $mpesaLog->response_description = $response['ResponseDescription'] ?? null;
            } else {
                $mpesaLog->markAsFailed($response['ResponseDescription'] ?? 'STK Push failed');
            }

            $mpesaLog->save();
            
            DB::commit();

            if ($mpesaLog->status === 'pending') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'mpesa_log_id' => $mpesaLog->id,
                        'checkout_request_id' => $mpesaLog->checkout_request_id,
                        'merchant_request_id' => $mpesaLog->merchant_request_id,
                        'response_code' => $mpesaLog->response_code,
                        'response_description' => $mpesaLog->response_description,
                    ],
                    'message' => 'STK Push initiated successfully. Please check your phone for payment prompt.'
                ], 201);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'STK Push failed: ' . ($response['ResponseDescription'] ?? 'Unknown error'),
                    'data' => ['mpesa_log_id' => $mpesaLog->id]
                ], 422);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('STK Push Error: ' . $e->getMessage(), [
                'phone' => $request->phone_number,
                'amount' => $request->amount,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error initiating STK Push: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STK Push Callback
     * Handles the callback from M-Pesa after STK Push completion
     */
    public function stkCallback(Request $request): JsonResponse
    {
        Log::info('STK Push Callback Received', $request->all());

        try {
            $callbackData = $request->all();
            
            if (!isset($callbackData['Body']['stkCallback'])) {
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
            }

            $callback = $callbackData['Body']['stkCallback'];
            $checkoutRequestId = $callback['CheckoutRequestID'] ?? null;
            $resultCode = $callback['ResultCode'] ?? null;

            // Find the M-Pesa log entry
            $mpesaLog = MpesaLog::where('checkout_request_id', $checkoutRequestId)->first();

            if (!$mpesaLog) {
                Log::warning('STK Callback: M-Pesa log not found for CheckoutRequestID: ' . $checkoutRequestId);
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
            }

            // Process successful transaction
            if ($resultCode === 0) {
                $callbackMetadata = $callback['CallbackMetadata']['Item'] ?? [];
                $transactionId = null;
                $phoneNumber = null;
                $amount = null;

                foreach ($callbackMetadata as $item) {
                    switch ($item['Name']) {
                        case 'MpesaReceiptNumber':
                            $transactionId = $item['Value'];
                            break;
                        case 'PhoneNumber':
                            $phoneNumber = $item['Value'];
                            break;
                        case 'Amount':
                            $amount = $item['Value'];
                            break;
                    }
                }

                $mpesaLog->mpesa_receipt_number = $transactionId;
                $mpesaLog->mpesa_transaction_id = $transactionId;
                $mpesaLog->markAsCompleted($callback);

                // Create or update linked transaction
                if ($mpesaLog->transaction_id) {
                    $transaction = Transaction::find($mpesaLog->transaction_id);
                    if ($transaction) {
                        $transaction->mpesa_receipt_number = $transactionId;
                        $transaction->mpesa_transaction_id = $transactionId;
                        $transaction->status = 'completed';
                        $transaction->save();
                    }
                } else {
                    // Create new transaction record for incoming payment
                    Transaction::create([
                        'type' => 'income',
                        'category' => 'sales',
                        'amount' => $amount,
                        'payment_method' => 'mpesa',
                        'description' => 'M-Pesa payment received',
                        'phone_number' => $phoneNumber,
                        'mpesa_receipt_number' => $transactionId,
                        'mpesa_transaction_id' => $transactionId,
                        'mpesa_type' => 'STK_PUSH',
                        'transaction_date' => now(),
                        'status' => 'completed',
                        'created_by_user_id' => Auth::id(),
                    ]);
                }

            } else {
                // Handle failed transaction
                $mpesaLog->markAsFailed($callback['ResultDesc'] ?? 'Payment cancelled by user');
            }

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback processed successfully']);

        } catch (\Exception $e) {
            Log::error('STK Callback Error: ' . $e->getMessage(), [
                'callback_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Callback processing failed']);
        }
    }

    /**
     * Business to Customer (B2C) Payment
     * Sends money from business to customer
     */
    public function businessToCustomer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => ['required', 'string', 'regex:/^(?:\+254|254|0)?[17]\d{8}$/'],
            'amount' => 'required|numeric|min:10|max:150000',
            'occasion' => 'required|string|max:100',
            'remarks' => 'required|string|max:100',
            'pending_payment_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // Format phone number
            $phoneNumber = $this->formatPhoneNumber($request->phone_number);

            // Create M-Pesa log entry
            $mpesaLog = MpesaLog::createB2CLog([
                'InitiatorName' => config('mpesa.initiator_name'),
                'SecurityCredential' => $this->getSecurityCredential(),
                'CommandID' => 'BusinessPayment',
                'Amount' => $request->amount,
                'PartyA' => $this->shortcode,
                'PartyB' => $phoneNumber,
                'Remarks' => $request->remarks,
                'QueueTimeOutURL' => route('mpesa.b2c-timeout'),
                'ResultURL' => route('mpesa.b2c-callback'),
                'Occasion' => $request->occasion,
            ]);

            // Link to pending payment if provided
            if ($request->pending_payment_id) {
                $pendingPayment = PendingPayment::find($request->pending_payment_id);
                if ($pendingPayment) {
                    $mpesaLog->linked_id = $pendingPayment->id;
                    $mpesaLog->linked_type = 'pending_payment';
                    $mpesaLog->save();
                }
            }

            // In a real implementation, this would call the M-Pesa API
            $response = $this->callMpesaAPI('mpesa/b2c/v1/paymentrequest', [
                'InitiatorName' => config('mpesa.initiator_name'),
                'SecurityCredential' => $this->getSecurityCredential(),
                'CommandID' => 'BusinessPayment',
                'Amount' => $request->amount,
                'PartyA' => $this->shortcode,
                'PartyB' => $phoneNumber,
                'Remarks' => $request->remarks,
                'QueueTimeOutURL' => route('mpesa.b2c-timeout'),
                'ResultURL' => route('mpesa.b2c-callback'),
                'Occasion' => $request->occasion,
            ]);

            // Update log with response
            $mpesaLog->response_payload = $response;
            
            if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
                $mpesaLog->status = 'pending';
                $mpesaLog->response_code = $response['ResponseCode'];
                $mpesaLog->response_description = $response['ResponseDescription'] ?? null;
            } else {
                $mpesaLog->markAsFailed($response['ResponseDescription'] ?? 'B2C payment failed');
            }

            $mpesaLog->save();
            
            DB::commit();

            if ($mpesaLog->status === 'pending') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'mpesa_log_id' => $mpesaLog->id,
                        'response_code' => $mpesaLog->response_code,
                        'response_description' => $mpesaLog->response_description,
                    ],
                    'message' => 'B2C payment initiated successfully. Customer will receive the money shortly.'
                ], 201);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'B2C payment failed: ' . ($response['ResponseDescription'] ?? 'Unknown error'),
                    'data' => ['mpesa_log_id' => $mpesaLog->id]
                ], 422);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('B2C Payment Error: ' . $e->getMessage(), [
                'phone' => $request->phone_number,
                'amount' => $request->amount,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error initiating B2C payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * B2C Callback
     * Handles the callback from M-Pesa after B2C completion
     */
    public function b2cCallback(Request $request): JsonResponse
    {
        Log::info('B2C Callback Received', $request->all());

        try {
            $callbackData = $request->all();
            
            if (!isset($callbackData['Result'])) {
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
            }

            $result = $callbackData['Result'];
            $conversationId = $result['ConversationID'] ?? null;
            $resultCode = $result['ResultCode'] ?? null;

            // Find the M-Pesa log entry by conversation ID or other identifier
            $mpesaLog = MpesaLog::where('response_payload->ConversationID', $conversationId)
                               ->orWhere('response_payload->OriginatorConversationID', $conversationId)
                               ->first();

            if (!$mpesaLog) {
                Log::warning('B2C Callback: M-Pesa log not found for ConversationID: ' . $conversationId);
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
            }

            // Process successful transaction
            if ($resultCode === 0) {
                $resultParameters = $result['ResultParameters']['ResultParameter'] ?? [];
                $transactionId = null;

                foreach ($resultParameters as $param) {
                    if ($param['Key'] === 'TransactionReceipt') {
                        $transactionId = $param['Value'];
                        break;
                    }
                }

                $mpesaLog->mpesa_receipt_number = $transactionId;
                $mpesaLog->mpesa_transaction_id = $transactionId;
                $mpesaLog->markAsCompleted($result);

                // Update linked pending payment
                if ($mpesaLog->linked_type === 'pending_payment' && $mpesaLog->linked_id) {
                    $pendingPayment = PendingPayment::find($mpesaLog->linked_id);
                    if ($pendingPayment) {
                        $pendingPayment->markCompleted();
                        
                        // Create transaction record
                        if ($pendingPayment->transaction) {
                            $pendingPayment->transaction->update([
                                'mpesa_receipt_number' => $transactionId,
                                'mpesa_transaction_id' => $transactionId,
                                'status' => 'completed',
                            ]);
                        }
                    }
                }

            } else {
                // Handle failed transaction
                $mpesaLog->markAsFailed($result['ResultDesc'] ?? 'B2C payment failed');

                // Update linked pending payment as failed
                if ($mpesaLog->linked_type === 'pending_payment' && $mpesaLog->linked_id) {
                    $pendingPayment = PendingPayment::find($mpesaLog->linked_id);
                    if ($pendingPayment) {
                        $pendingPayment->markFailed($result['ResultDesc'] ?? 'B2C payment failed');
                    }
                }
            }

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback processed successfully']);

        } catch (\Exception $e) {
            Log::error('B2C Callback Error: ' . $e->getMessage(), [
                'callback_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Callback processing failed']);
        }
    }

    /**
     * C2B Confirmation
     * Confirms payments received from customers
     */
    public function c2bConfirmation(Request $request): JsonResponse
    {
        Log::info('C2B Confirmation Received', $request->all());

        try {
            $callbackData = $request->all();

            // Create M-Pesa log entry
            $mpesaLog = MpesaLog::createC2BLog($callbackData);

            // Check for duplicate
            $duplicate = $mpesaLog->checkForDuplicate();
            if ($duplicate) {
                $mpesaLog->markAsDuplicate($duplicate);
                Log::warning('C2B Duplicate Transaction Detected', [
                    'receipt' => $mpesaLog->mpesa_receipt_number,
                    'original_id' => $duplicate->id
                ]);
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Duplicate transaction']);
            }

            // Create transaction record for incoming payment
            $transaction = Transaction::create([
                'type' => 'income',
                'category' => 'sales',
                'amount' => $callbackData['TransAmount'] ?? 0,
                'payment_method' => 'mpesa',
                'description' => 'M-Pesa payment from ' . ($callbackData['FirstName'] ?? 'Customer'),
                'phone_number' => $callbackData['MSISDN'] ?? null,
                'mpesa_receipt_number' => $callbackData['TransID'] ?? null,
                'mpesa_transaction_id' => $callbackData['TransID'] ?? null,
                'mpesa_type' => 'C2B',
                'transaction_date' => now(),
                'status' => 'completed',
                'created_by_user_id' => 1, // System user for C2B transactions
            ]);

            // Link transaction to M-Pesa log
            $mpesaLog->transaction_id = $transaction->id;
            $mpesaLog->save();

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Success']);

        } catch (\Exception $e) {
            Log::error('C2B Confirmation Error: ' . $e->getMessage(), [
                'callback_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Internal server error']);
        }
    }

    /**
     * C2B Validation
     * Validates incoming C2B payments before confirmation
     */
    public function c2bValidation(Request $request): JsonResponse
    {
        Log::info('C2B Validation Received', $request->all());

        try {
            $callbackData = $request->all();
            
            // Validate amount (minimum 1 KES)
            $amount = $callbackData['TransAmount'] ?? 0;
            if ($amount < 1) {
                return response()->json([
                    'ResultCode' => 'C2B00011', 
                    'ResultDesc' => 'Invalid amount'
                ]);
            }

            // Additional validation logic can be added here
            // For example, check account reference, validate customer, etc.

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Success']);

        } catch (\Exception $e) {
            Log::error('C2B Validation Error: ' . $e->getMessage(), [
                'callback_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ResultCode' => 'C2B00012', 
                'ResultDesc' => 'Internal server error'
            ]);
        }
    }

    /**
     * Transaction Status Query
     * Queries the status of a specific M-Pesa transaction
     */
    public function transactionStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'occasion' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            // In a real implementation, this would call the M-Pesa API
            $response = $this->callMpesaAPI('mpesa/transactionstatus/v1/query', [
                'Initiator' => config('mpesa.initiator_name'),
                'SecurityCredential' => $this->getSecurityCredential(),
                'CommandID' => 'TransactionStatusQuery',
                'TransactionID' => $request->transaction_id,
                'PartyA' => $this->shortcode,
                'IdentifierType' => '4',
                'ResultURL' => route('mpesa.status-callback'),
                'QueueTimeOutURL' => route('mpesa.status-timeout'),
                'Remarks' => 'Transaction status query',
                'Occasion' => $request->occasion ?? 'Status check',
            ]);

            return response()->json([
                'success' => true,
                'data' => $response,
                'message' => 'Transaction status query initiated'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error querying transaction status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Account Balance Query
     * Queries the account balance
     */
    public function accountBalance(Request $request): JsonResponse
    {
        try {
            // In a real implementation, this would call the M-Pesa API
            $response = $this->callMpesaAPI('mpesa/accountbalance/v1/query', [
                'Initiator' => config('mpesa.initiator_name'),
                'SecurityCredential' => $this->getSecurityCredential(),
                'CommandID' => 'AccountBalance',
                'PartyA' => $this->shortcode,
                'IdentifierType' => '4',
                'Remarks' => 'Account balance query',
                'QueueTimeOutURL' => route('mpesa.balance-timeout'),
                'ResultURL' => route('mpesa.balance-callback'),
            ]);

            return response()->json([
                'success' => true,
                'data' => $response,
                'message' => 'Account balance query initiated'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error querying account balance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get M-Pesa Logs
     */
    public function logs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_type' => 'nullable|in:C2B,B2C,STK_PUSH,REVERSAL,BALANCE_INQUIRY',
            'status' => 'nullable|in:initiated,pending,processing,completed,failed,timeout,cancelled,reversed,duplicate',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'phone' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $query = MpesaLog::query()->with(['transaction', 'createdBy:id,name']);

            // Apply filters
            if ($request->transaction_type) {
                $query->where('transaction_type', $request->transaction_type);
            }
            if ($request->status) {
                $query->where('status', $request->status);
            }
            if ($request->start_date) {
                $query->where('created_at', '>=', $request->start_date);
            }
            if ($request->end_date) {
                $query->where('created_at', '<=', $request->end_date);
            }
            if ($request->phone) {
                $query->byPhone($request->phone);
            }

            $query->orderBy('created_at', 'desc');

            $perPage = $request->get('per_page', 20);
            $logs = $query->paginate($perPage);

            // Add computed fields
            $logs->getCollection()->transform(function ($log) {
                $log->status_badge_color = $log->getStatusBadgeColor();
                $log->readable_status = $log->getReadableStatus();
                return $log;
            });

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching M-Pesa logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get M-Pesa Analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->subMonth();
            $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now();

            $stats = MpesaLog::getTransactionStats($startDate, $endDate);
            $failureAnalysis = MpesaLog::getFailureAnalysis($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_stats' => $stats,
                    'failure_analysis' => $failureAnalysis,
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating M-Pesa analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper Methods
     */

    protected function formatPhoneNumber(string $phone): string
    {
        // Remove any non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        
        // Convert to international format (254...)
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '+254')) {
            $phone = substr($phone, 1);
        } elseif (!str_starts_with($phone, '254')) {
            $phone = '254' . $phone;
        }
        
        return $phone;
    }

    protected function getAccessToken(): ?string
    {
        if ($this->token && $this->isTokenValid()) {
            return $this->token;
        }

        try {
            $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
            
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['access_token'] ?? null;
                return $this->token;
            }

            return null;

        } catch (\Exception $e) {
            Log::error('M-Pesa Token Error: ' . $e->getMessage());
            return null;
        }
    }

    protected function isTokenValid(): bool
    {
        // Simple token validation - in production, you'd store token expiry
        return !empty($this->token);
    }

    protected function getSecurityCredential(): string
    {
        // In production, this would encrypt the initiator password with M-Pesa public key
        return base64_encode(config('mpesa.initiator_password', 'test_password'));
    }

    protected function callMpesaAPI(string $endpoint, array $data): array
    {
        // This is a mock implementation for development
        // In production, this would make actual API calls to M-Pesa
        
        Log::info('M-Pesa API Call (Mock)', [
            'endpoint' => $endpoint,
            'data' => $data
        ]);

        // Simulate different responses based on endpoint
        if (str_contains($endpoint, 'stkpush')) {
            return [
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'MerchantRequestID' => 'mock-merchant-' . uniqid(),
                'CheckoutRequestID' => 'mock-checkout-' . uniqid(),
                'CustomerMessage' => 'Success. Request accepted for processing'
            ];
        }

        if (str_contains($endpoint, 'b2c')) {
            return [
                'ResponseCode' => '0',
                'ResponseDescription' => 'Accept the service request successfully.',
                'OriginatorConversationID' => 'mock-originator-' . uniqid(),
                'ConversationID' => 'mock-conversation-' . uniqid(),
            ];
        }

        // Default success response
        return [
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success',
        ];
    }
}

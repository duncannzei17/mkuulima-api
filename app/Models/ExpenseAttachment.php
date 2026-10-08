<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ExpenseAttachment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'expense_id',
        'filename',
        'original_filename',
        'file_path',
        'file_type',
        'file_size',
        'attachment_type',
        'description',
        'image_width',
        'image_height',
        'exif_data',
        'processing_status',
        'processing_error',
        'extracted_data',
        'data_verified',
        'uploaded_by',
        'is_public',
        'cloud_url',
        'cloud_provider',
        'is_backed_up',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'image_width' => 'integer',
        'image_height' => 'integer',
        'exif_data' => 'array',
        'extracted_data' => 'array',
        'data_verified' => 'boolean',
        'is_public' => 'boolean',
        'is_backed_up' => 'boolean',
    ];

    protected $attributes = [
        'processing_status' => 'pending',
        'data_verified' => false,
        'is_public' => false,
        'is_backed_up' => false,
    ];

    // Relationships
    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // Scopes
    public function scopeImages($query)
    {
        return $query->where('file_type', 'like', 'image/%');
    }

    public function scopeDocuments($query)
    {
        return $query->where('file_type', 'not like', 'image/%');
    }

    public function scopeProcessed($query)
    {
        return $query->where('processing_status', 'processed');
    }

    public function scopePending($query)
    {
        return $query->where('processing_status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('processing_status', 'failed');
    }

    public function scopeReceipts($query)
    {
        return $query->whereIn('attachment_type', ['receipt_photo', 'receipt_document']);
    }

    // Accessors
    public function getFileSizeHumanAttribute()
    {
        $bytes = $this->file_size;
        
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    public function getIsImageAttribute()
    {
        return str_starts_with($this->file_type, 'image/');
    }

    public function getIsDocumentAttribute()
    {
        return !$this->is_image;
    }

    public function getFileExtensionAttribute()
    {
        return pathinfo($this->original_filename, PATHINFO_EXTENSION);
    }

    public function getPublicUrlAttribute()
    {
        if ($this->cloud_url) {
            return $this->cloud_url;
        }
        
        // Generate local URL if file exists
        if (file_exists($this->file_path)) {
            return asset('storage/' . str_replace(storage_path('app/public/'), '', $this->file_path));
        }
        
        return null;
    }

    public function getThumbnailUrlAttribute()
    {
        if (!$this->is_image) return null;
        
        // Generate thumbnail URL (assuming a thumbnail generation service)
        return $this->public_url . '?w=150&h=150&fit=crop';
    }

    public function getDownloadUrlAttribute()
    {
        return route('api.expense-attachments.download', $this->id);
    }

    // Business Logic Methods
    public function markAsProcessed($extractedData = null)
    {
        $this->processing_status = 'processed';
        $this->processing_error = null;
        
        if ($extractedData) {
            $this->extracted_data = $extractedData;
        }
        
        $this->save();
    }

    public function markAsFailed($error)
    {
        $this->processing_status = 'failed';
        $this->processing_error = $error;
        $this->save();
    }

    public function verifyExtractedData()
    {
        $this->data_verified = true;
        $this->save();
    }

    public function updateExtractedData($data)
    {
        $this->extracted_data = array_merge($this->extracted_data ?? [], $data);
        $this->data_verified = false; // Reset verification when data changes
        $this->save();
    }

    public function backupToCloud($cloudUrl, $provider = 'aws')
    {
        $this->cloud_url = $cloudUrl;
        $this->cloud_provider = $provider;
        $this->is_backed_up = true;
        $this->save();
    }

    public function extractImageMetadata()
    {
        if (!$this->is_image || !file_exists($this->file_path)) {
            return false;
        }

        try {
            // Get image dimensions
            $imageInfo = getimagesize($this->file_path);
            if ($imageInfo) {
                $this->image_width = $imageInfo[0];
                $this->image_height = $imageInfo[1];
            }

            // Get EXIF data
            if (function_exists('exif_read_data') && in_array($this->file_extension, ['jpg', 'jpeg', 'tiff'])) {
                $exifData = exif_read_data($this->file_path);
                if ($exifData) {
                    // Filter out binary data and keep useful metadata
                    $cleanExifData = [];
                    
                    // Camera information
                    if (isset($exifData['Make'])) $cleanExifData['camera_make'] = $exifData['Make'];
                    if (isset($exifData['Model'])) $cleanExifData['camera_model'] = $exifData['Model'];
                    
                    // Date/time
                    if (isset($exifData['DateTime'])) $cleanExifData['date_taken'] = $exifData['DateTime'];
                    
                    // GPS data
                    if (isset($exifData['GPSLatitude']) && isset($exifData['GPSLongitude'])) {
                        $cleanExifData['gps_latitude'] = $this->convertDMSToDD($exifData['GPSLatitude'], $exifData['GPSLatitudeRef']);
                        $cleanExifData['gps_longitude'] = $this->convertDMSToDD($exifData['GPSLongitude'], $exifData['GPSLongitudeRef']);
                    }
                    
                    $this->exif_data = $cleanExifData;
                }
            }

            $this->save();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function convertDMSToDD($dms, $ref)
    {
        if (!is_array($dms) || count($dms) < 3) return null;
        
        $degrees = $this->convertFractionToDecimal($dms[0]);
        $minutes = $this->convertFractionToDecimal($dms[1]);
        $seconds = $this->convertFractionToDecimal($dms[2]);
        
        $dd = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if ($ref == 'S' || $ref == 'W') {
            $dd = $dd * -1;
        }
        
        return $dd;
    }

    private function convertFractionToDecimal($fraction)
    {
        if (strpos($fraction, '/') !== false) {
            $parts = explode('/', $fraction);
            return floatval($parts[0]) / floatval($parts[1]);
        }
        return floatval($fraction);
    }

    public function delete()
    {
        // Delete physical file when model is deleted
        if (file_exists($this->file_path)) {
            unlink($this->file_path);
        }
        
        return parent::delete();
    }

    // Static utility methods
    public static function getAttachmentTypes()
    {
        return [
            'receipt_photo' => 'Receipt Photo',
            'receipt_document' => 'Receipt Document',
            'invoice' => 'Invoice',
            'proof_of_payment' => 'Proof of Payment',
            'delivery_note' => 'Delivery Note',
            'other' => 'Other',
        ];
    }

    public static function getSupportedFileTypes()
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    public static function getMaxFileSize()
    {
        return 10 * 1024 * 1024; // 10MB
    }

    public static function generateStoragePath($expenseId, $originalFilename)
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        
        return "expense_attachments/{$expenseId}/{$filename}";
    }
}
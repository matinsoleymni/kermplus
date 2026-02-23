<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whitelisted_targets', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['user_id', 'type'], 'whitelisted_targets_user_type_index');
            $table->unique(['user_id', 'type'], 'whitelisted_targets_user_type_unique');
        });

        $records = DB::table('usage_records')
            ->where('type', 'whitelist_add')
            ->whereNotNull('target')
            ->select('user_id', 'target')
            ->orderBy('id')
            ->get();

        foreach ($records as $record) {
            $target = trim((string) $record->target);
            if ($target === '') {
                continue;
            }

            $type = $this->guessType($target);
            $normalized = $this->normalizeByType($target, $type);

            DB::table('whitelisted_targets')
                ->where('type', $type)
                ->where('normalized_value', $normalized)
                ->whereNull('user_id')
                ->update(['user_id' => $record->user_id]);
        }
    }

    public function down(): void
    {
        Schema::table('whitelisted_targets', function (Blueprint $table) {
            $table->dropUnique('whitelisted_targets_user_type_unique');
            $table->dropIndex('whitelisted_targets_user_type_index');
            $table->dropConstrainedForeignId('user_id');
        });
    }

    private function guessType(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        $normalizedPhone = $this->normalizeByType($value, 'phone');
        if (preg_match('/^09\d{9}$/', $normalizedPhone)) {
            return 'phone';
        }

        if (preg_match('#^(?:https?://)?(?:t\.me|telegram\.me)/#i', $value) || str_contains($value, '@')) {
            return 'telegram';
        }

        return 'custom';
    }

    private function normalizeByType(string $value, string $type): string
    {
        $value = trim($value);

        if ($type === 'email' || $type === 'instagram_email') {
            return strtolower($value);
        }

        if ($type === 'telegram') {
            if (preg_match('#^(?:https?://)?(?:t\.me|telegram\.me)/([^/?#]+)(?:/[^?#]+)?#i', $value, $matches)) {
                $value = $matches[1];
            }

            return strtolower(ltrim($value, '@'));
        }

        if ($type === 'phone') {
            $digits = preg_replace('/\D+/', '', $value);

            if (str_starts_with($digits, '0098')) {
                $digits = substr($digits, 2);
            }

            if (str_starts_with($digits, '98')) {
                $digits = '0' . substr($digits, 2);
            }

            if (!str_starts_with($digits, '0') && strlen($digits) === 10) {
                $digits = '0' . $digits;
            }

            return $digits;
        }

        return strtolower($value);
    }
};

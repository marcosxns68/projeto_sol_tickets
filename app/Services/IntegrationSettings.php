<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IntegrationSettings
{
    private function key(int $integrationId, string $name): string
    {
        return 'integration.'.$integrationId.'.'.$name;
    }

    public function get(int $integrationId, string $name, mixed $default = null): mixed
    {
        $value = DB::table('settings')->where('key', $this->key($integrationId, $name))->value('value');

        return $value === null ? $default : $value;
    }

    public function put(int $integrationId, string $name, mixed $value): void
    {
        $key = $this->key($integrationId, $name);

        if ($value === null || $value === '') {
            DB::table('settings')->where('key', $key)->delete();
            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => (string) $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function departmentId(int $integrationId): ?int
    {
        $value = $this->get($integrationId, 'department_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function touchApi(int $integrationId): void
    {
        $this->put($integrationId, 'last_api_activity_at', now()->toIso8601String());
    }
}

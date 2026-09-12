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

    public function labelIds(int $integrationId): array
    {
        $decoded = json_decode((string) $this->get($integrationId, 'default_label_ids', '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $decoded),
            fn (int $id) => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $existing = DB::table('labels')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $existing, true)));
    }

    public function putLabelIds(int $integrationId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $valid = DB::table('labels')->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->put($integrationId, 'default_label_ids', json_encode($valid, JSON_UNESCAPED_UNICODE));
    }

    public function touchApi(int $integrationId): void
    {
        $this->put($integrationId, 'last_api_activity_at', now()->toIso8601String());
    }
}

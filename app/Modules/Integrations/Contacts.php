<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Support\Database\Records as R;
use App\Support\Security\SecretBox;
use Illuminate\Support\Facades\DB;

final class Contacts
{
    public function upsert(string $workspace, string $brand, string $issuer, string $subject, array $input): object
    {
        return DB::transaction(function () use ($workspace, $brand, $issuer, $subject, $input): object {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$brand.'|'.$issuer.'|'.$subject]);
            $identity = DB::table('contact_identities')->where('brand_id', $brand)->where('issuer', $issuer)->where('subject', $subject)->first();
            $id = $identity->contact_id ?? R::id();
            $values = ['name' => $input['name'] ?? '客戶', 'attributes' => R::encode($input['attributes'] ?? []), 'updated_at' => now()];
            if (isset($input['email'])) {
                $values['email_encrypted'] = app(SecretBox::class)->encrypt($input['email'], 'contact_email:'.$id);
                $values['email_lookup_digest'] = hash_hmac('sha256', mb_strtolower($input['email']), (string) config('yacs.secrets.lookup_key'));
            }
            if ($identity === null) {
                DB::table('contacts')->insert(['id' => $id, 'workspace_id' => $workspace, 'brand_id' => $brand, 'created_at' => now()] + $values);
                DB::table('contact_identities')->insert(['id' => R::id(), 'workspace_id' => $workspace, 'brand_id' => $brand, 'contact_id' => $id, 'issuer' => $issuer, 'subject' => $subject, 'verified_at' => now(), 'created_at' => now()]);
            } else {
                DB::table('contacts')->where('id', $id)->update($values);
            }

            return DB::table('contacts')->where('id', $id)->first();
        });
    }

    public static function dto(object $c): array
    {
        return ['id' => $c->id, 'workspace_id' => $c->workspace_id, 'brand_id' => $c->brand_id, 'name' => $c->name, 'identity_level' => 'verified'];
    }
}

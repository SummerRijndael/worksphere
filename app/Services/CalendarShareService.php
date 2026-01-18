<?php

namespace App\Services;

use App\Contracts\CalendarShareContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CalendarShareService implements CalendarShareContract
{
    public function getShares(User $user): array
    {
        // Who has shared with me?
        $sharedWithMe = DB::table('calendar_shares')
            ->join('users', 'calendar_shares.user_id', '=', 'users.id')
            ->where('calendar_shares.shared_with_user_id', $user->id)
            ->select('users.id', 'users.name', 'users.email', 'users.public_id', 'calendar_shares.permission_level')
            ->get();

        // Who have I shared with?
        $myShares = DB::table('calendar_shares')
            ->join('users', 'calendar_shares.shared_with_user_id', '=', 'users.id')
            ->where('calendar_shares.user_id', $user->id)
            ->select('users.id', 'users.name', 'users.email', 'users.public_id', 'calendar_shares.permission_level', 'calendar_shares.id as share_id')
            ->get();

        return [
            'shared_with_me' => $sharedWithMe,
            'my_shares' => $myShares,
        ];
    }

    public function share(User $owner, string $email, string $permission): void
    {
        $recipient = User::where('email', $email)->firstOrFail();

        if ($recipient->id === $owner->id) {
            throw new \InvalidArgumentException('Cannot share with yourself.');
        }

        DB::table('calendar_shares')->updateOrInsert(
            [
                'user_id' => $owner->id,
                'shared_with_user_id' => $recipient->id,
            ],
            [
                'permission_level' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function updatePermission(User $owner, int $shareId, string $permission): bool
    {
        return (bool) DB::table('calendar_shares')
            ->where('id', $shareId)
            ->where('user_id', $owner->id)
            ->update([
                'permission_level' => $permission,
                'updated_at' => now(),
            ]);
    }

    public function revoke(User $owner, int $shareId): bool
    {
        return (bool) DB::table('calendar_shares')
            ->where('id', $shareId)
            ->where('user_id', $owner->id)
            ->delete();
    }
}

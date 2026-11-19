<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Category;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\DB;

final class RegisterUser
{
    /** Default expense categories (Requirement, section 8). */
    public const DEFAULT_EXPENSE_CATEGORIES = [
        'อาหาร', 'เดินทาง', 'ช้อปปิ้ง', 'ของใช้ส่วนตัว', 'ค่าใช้จ่ายในบ้าน',
        'สุขภาพ', 'ความบันเทิง', 'สัตว์เลี้ยง', 'การลงทุน', 'อื่น ๆ',
    ];

    /** @param array{name: string, email: string, password: string} $data */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // hashed by the model cast
            ]);

            (new UserSetting())->forceFill(['user_id' => $user->id])->save();

            foreach (self::DEFAULT_EXPENSE_CATEGORIES as $index => $name) {
                (new Category())->forceFill([
                    'user_id' => $user->id, // nobody is logged in yet, so set the owner explicitly
                    'name' => $name,
                    'kind' => 'expense',
                    'is_default' => true,
                    'sort_order' => $index + 1,
                ])->save();
            }

            return $user;
        });
    }
}
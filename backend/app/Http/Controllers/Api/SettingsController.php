<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\SettingsResource;
use App\Models\UserSetting;

class SettingsController extends Controller
{
    public function show(): SettingsResource
    {
        return new SettingsResource(UserSetting::query()->firstOrFail());
    }

    public function update(SettingsRequest $request): SettingsResource
    {
        $settings = UserSetting::query()->firstOrFail();
        $settings->fill($request->validated())->save();

        return new SettingsResource($settings->refresh());
    }
}
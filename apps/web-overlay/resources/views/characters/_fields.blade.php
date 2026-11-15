<label class="grid gap-1">Name<input name="name" required maxlength="60" value="{{ $v['name'] ?? '' }}" class="rounded border border-gray-300 px-2 py-1"></label>
<label class="grid gap-1">Type
    <select name="kind" class="rounded border border-gray-300 px-2 py-1">
        @foreach (['male' => 'Male', 'female' => 'Female', 'custom' => 'Custom'] as $k => $l)<option value="{{ $k }}" @selected(($v['kind'] ?? 'custom') === $k)>{{ $l }}</option>@endforeach
    </select>
</label>
<label class="grid gap-1">Personality<textarea name="personality" required maxlength="1000" rows="3" class="rounded border border-gray-300 px-2 py-1">{{ $v['personality'] ?? '' }}</textarea></label>
<label class="grid gap-1">Speaking style<textarea name="speaking_style" maxlength="500" rows="2" class="rounded border border-gray-300 px-2 py-1">{{ $v['speaking_style'] ?? '' }}</textarea></label>
<label class="grid gap-1">Greeting message<input name="greeting" maxlength="300" value="{{ $v['greeting'] ?? '' }}" class="rounded border border-gray-300 px-2 py-1"></label>
<div class="grid gap-2 sm:grid-cols-3">
    <label class="grid gap-1">Language
        <select name="language" class="rounded border border-gray-300 px-2 py-1">
            @foreach (['bn' => 'Bengali', 'en' => 'English', 'auto' => 'User’s language'] as $k => $l)<option value="{{ $k }}" @selected(($v['language'] ?? 'bn') === $k)>{{ $l }}</option>@endforeach
        </select>
    </label>
    <label class="grid gap-1">Voice (optional)<input name="voice" maxlength="60" value="{{ $v['voice'] ?? '' }}" class="rounded border border-gray-300 px-2 py-1"></label>
    <label class="grid gap-1">Avatar
        <select name="avatar_style" class="rounded border border-gray-300 px-2 py-1">
            @foreach (['none' => 'None', '2d' => '2D', '3d' => '3D'] as $k => $l)<option value="{{ $k }}" @selected(($v['avatar_style'] ?? 'none') === $k)>{{ $l }}</option>@endforeach
        </select>
    </label>
</div>

@props(['name', 'label', 'type' => 'text', 'value' => null, 'autocomplete' => null])
<div class="space-y-1">
    <label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $type === 'password' ? '' : old($name, $value) }}"
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           {{ $attributes->merge(['class' => 'w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:outline-none']) }}>
    @error($name)
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>

@props(['variant' => 'primary'])
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'rounded-md px-4 py-2 text-sm font-medium '.($variant === 'danger' ? 'bg-red-600 text-white hover:bg-red-700' : ($variant === 'ghost' ? 'border border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-indigo-600 text-white hover:bg-indigo-700'))]) }}>
    {{ $slot }}
</button>

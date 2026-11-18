<x-mail::message>
# {{ __('A store was connected to your account') }}

{{ __('مرحباً :name، تم ربط متجرك :store بحسابك الحالي. سجّل الدخول للوصول إلى مساحة العمل الجديدة :workspace.', ['name' => $user->name, 'store' => $storeName, 'workspace' => $workspaceName ?? $storeName]) }}

{{ __('Hi :name, your store :store was linked to your existing account. Sign in to access the new :workspace workspace.', ['name' => $user->name, 'store' => $storeName, 'workspace' => $workspaceName ?? $storeName]) }}

<x-mail::button :url="$loginUrl">
{{ __('Sign in') }}
</x-mail::button>

{{ __('Thanks,') }}
{{ config('platforms.mail.brand_name') ?? config('app.name') }}
</x-mail::message>

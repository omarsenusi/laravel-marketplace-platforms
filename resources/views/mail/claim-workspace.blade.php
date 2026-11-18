<x-mail::message>
# {{ __('Set up your workspace for :store', ['store' => $storeName]) }}

{{ __('مرحباً :name، تم توصيل متجرك :store بمنصة :app. الخطوة الأخيرة هي تعيين كلمة مرور لتفعيل مساحة العمل الخاصة بك.', ['name' => $user->name, 'store' => $storeName, 'app' => config('platforms.mail.brand_name') ?? config('app.name')]) }}

{{ __('Hi :name, your store :store was connected to :app. The last step is to set a password to activate your workspace.', ['name' => $user->name, 'store' => $storeName, 'app' => config('platforms.mail.brand_name') ?? config('app.name')]) }}

<x-mail::button :url="$claimUrl">
{{ __('Set your password') }}
</x-mail::button>

{{ __('This link was sent to :email and expires in :hours hours.', ['email' => $maskedEmail, 'hours' => $expiryHours]) }}

{{ __('Thanks,') }}
{{ config('platforms.mail.brand_name') ?? config('app.name') }}
</x-mail::message>

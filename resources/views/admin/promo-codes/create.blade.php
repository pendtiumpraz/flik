<x-admin.layout title="Create Promo Code">
    @include('admin.promo-codes._form', [
        'action'    => route('admin.promo-codes.store'),
        'method'    => 'POST',
        'promoCode' => null,
        'plans'     => $plans,
        'types'     => $types,
        'submit'    => 'Create Promo Code',
    ])
</x-admin.layout>

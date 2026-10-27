<div>
    @if ($productId)
        @include('livewire.forms.quick-order-styles')
        @include('livewire.forms.quick-order-script')
        @include('livewire.forms.quick-order')
    @else
        @include('livewire.forms.callback-basic')
    @endif
</div>
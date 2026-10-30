<div>
    @include('livewire.forms.quick-order-styles')
    @include('livewire.forms.quick-order-script')

    {{-- The form orders one product and says so throughout, so without one
         there is nothing to draw. Drawn anyway, it took the page down. --}}
    @if ($productSummary)
        @include('livewire.forms.quick-order')
    @endif
</div>

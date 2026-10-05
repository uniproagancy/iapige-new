<footer class="footer footer-static footer-light">
    <p class="clearfix mb-0">
        <span class="float-md-start d-block d-md-inline-block mt-25">
            © {{ date('Y') }} ELIO
        </span>
        <span class="float-md-end d-none d-md-block">
            {{ __('admin.products') }}: {{ \App\Models\Product::where('status', 'active')->count() }}
        </span>
    </p>
</footer>

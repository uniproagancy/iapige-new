<script>
	(() => {
		const state = window.IAPIBogCheckout ??= { bound: false, sdk: null, pageshowBound: false };
		const sdkUrl = 'https://webstatic.bog.ge/bog-sdk/bog-sdk.js?version=2&client_id=57315';
		const failedMessage = @json(__('checkout.payment_failed'));
		const failed = () => window.Livewire?.dispatch('toast', { type: 'error', message: failedMessage });

		const loadSdk = () => {
			if (window.BOG?.Calculator) return Promise.resolve(window.BOG);
			if (state.sdk) return state.sdk;
			let script;
			const pending = new Promise((resolve, reject) => {
				let timeout;
				const loaded = () => {
					clearTimeout(timeout);
					window.BOG?.Calculator ? resolve(window.BOG) : reject(new Error('BOG SDK unavailable'));
				};
				const error = () => { clearTimeout(timeout); reject(new Error('BOG SDK did not load')); };
				timeout = setTimeout(error, 15000);
				script = Array.from(document.scripts).find(item => item.src === sdkUrl);
				if (script) {
					script.addEventListener('load', loaded, { once: true });
					script.addEventListener('error', error, { once: true });
					return;
				}
				script = document.createElement('script');
				script.src = sdkUrl;
				script.async = true;
				script.dataset.iapiBogSdk = '1';
				script.addEventListener('load', loaded, { once: true });
				script.addEventListener('error', error, { once: true });
				document.head.append(script);
			});
			state.sdk = pending.catch(error => {
				if (script?.dataset.iapiBogSdk === '1') script.remove();
				state.sdk = null;
				throw error;
			});
			return state.sdk;
		};

		const openCalculator = (data, bnpl) => loadSdk().then(BOG => {
			BOG.Calculator.open({
				bnpl: bnpl,
				amount: data.amount,

				onRequest: (selected, success, close) => {
					fetch(data.url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'Accept': 'application/json',
							'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
						},
						body: JSON.stringify({
							amount: selected.amount,
							month: selected.month,
							discount_code: selected.discount_code,
						}),
					})
						.then(r => r.ok ? r.json() : Promise.reject(r))
						.then(body => success(body.orderId))
						.catch(() => { close(); failed(); });

					// the widget waits for success(); returning true would close it
					return false;
				},

				onComplete: () => false,
			});
		}).catch(failed);

		const bind = () => {
			if (state.bound || !window.Livewire) return;
			state.bound = true;
			window.Livewire.on('bog:installment', data => openCalculator(data[0] ?? data, false));
			window.Livewire.on('bog:installment-part', data => openCalculator(data[0] ?? data, true));
		};
		bind();
		document.addEventListener('livewire:initialized', bind, { once: true });

	/* The BOG widget paints an overlay of its own. Coming back from the bank the
	   browser restores that overlay but not the SDK state behind it, leaving a
	   page nothing can be clicked through. Start this one over instead. */
		if (!state.pageshowBound) {
			state.pageshowBound = true;
			window.addEventListener('pageshow', event => {
				if (event.persisted) window.location.reload();
			});
		}
	})();
</script>

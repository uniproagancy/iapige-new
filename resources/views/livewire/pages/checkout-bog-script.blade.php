<script src="https://webstatic.bog.ge/bog-sdk/bog-sdk.js?version=2&client_id=57315"></script>
<script>
	document.addEventListener('livewire:initialized', () => {
		const openCalculator = (data, bnpl) => {
			if (typeof BOG === 'undefined') {
				console.error('BOG SDK did not load');
				return;
			}

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
						.catch(() => close());

					// the widget waits for success(); returning true would close it
					return false;
				},

				onComplete: () => false,
			});
		};

		Livewire.on('bog:installment', (data) => openCalculator(data[0] ?? data, false));
		Livewire.on('bog:installment-part', (data) => openCalculator(data[0] ?? data, true));
	});
</script>

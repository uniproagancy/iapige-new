<div class="content-header mb-2"><h2>{{ __('warranty.agent.title') }}</h2></div>
<a href="{{ route('admin.warranties.index') }}" class="btn btn-outline-secondary mb-2">{{ __('warranty.agent.back') }}</a>
@if (!config('warranty.agent_enabled'))
    <div class="alert alert-warning">{{ __('warranty.agent.disabled') }}</div>
@endif
@if($oneTimeSecret ?? null)
    <div class="alert alert-success"><p>{{ __('warranty.agent.secret_once') }}</p><code style="overflow-wrap:anywhere">{{ $oneTimeSecret }}</code></div>
@endif
@if(session('status'))<div class="alert alert-info">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body">
    <h4>{{ __('warranty.agent.create') }}</h4>
    <form action="{{ route('admin.warranties.agent-keys.store') }}" method="post">@csrf
        <div class="row"><div class="col-md-6 mb-1"><label class="form-label" for="key-name">{{ __('warranty.agent.name') }}</label><input id="key-name" name="name" class="form-control" maxlength="100" required value="{{ old('name') }}"></div>
        <div class="col-md-6 mb-1"><label class="form-label" for="key-expires">{{ __('warranty.agent.expires') }}</label><input id="key-expires" name="expires_at" type="date" class="form-control" value="{{ old('expires_at') }}"></div></div>
        <div class="d-flex gap-2 flex-wrap mb-2">
        @foreach(['read','prepare','issue','send'] as $scope)
            <label class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope,old('scopes',['read','prepare']),true))><span class="form-check-label">{{ __('warranty.agent.scopes.'.$scope) }}</span></label>
        @endforeach
        </div><button class="btn btn-primary" type="submit">{{ __('warranty.agent.create') }}</button>
    </form>
</div></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>{{ __('warranty.agent.name') }}</th><th>{{ __('warranty.agent.permissions') }}</th><th>{{ __('warranty.agent.expires') }}</th><th>{{ __('warranty.agent.status') }}</th><th></th></tr></thead><tbody>
@forelse($keys as $key)<tr><td>{{ $key->name }}</td><td>@foreach($key->scopes as $scope)<span class="badge bg-secondary">{{ __('warranty.agent.scopes.'.$scope) }}</span> @endforeach</td><td>{{ $key->expires_at?->format('d.m.Y') ?? __('warranty.agent.no_expiry') }}</td><td>{{ $key->revoked_at || $key->expires_at?->isPast() ? __('warranty.agent.inactive') : __('warranty.agent.active') }}</td><td>@unless($key->revoked_at)<form method="post" action="{{ route('admin.warranties.agent-keys.revoke',$key) }}">@csrf<button class="btn btn-sm btn-outline-danger">{{ __('warranty.agent.revoke') }}</button></form>@endunless</td></tr>
@empty<tr><td colspan="5">{{ __('warranty.agent.empty') }}</td></tr>@endforelse
</tbody></table></div><div class="card-body">{{ $keys->links() }}</div></div>

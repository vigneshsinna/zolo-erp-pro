{{-- People command bar: users, sale agents and billers. Usage: <x-people-command-bar active="users"> buttons </x-people-command-bar> --}}
@props(['active' => null])
<x-section-command-bar title="People" icon="dripicons-user-group" badge="Logins, agents & billers" :active="$active" :links="[
    ['users', 'users-index', 'user.index', 'dripicons-user', __('db.User List')],
    ['sale-agents', 'sale-agents', 'sale-agents.index', 'dripicons-briefcase', __('db.Sale Agents')],
    ['billers', 'billers-index', 'biller.index', 'dripicons-store', __('db.Biller List')],
]">{{ $slot }}</x-section-command-bar>

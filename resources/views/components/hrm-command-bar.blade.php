{{-- HRM command bar: every HRM screen in one row. Usage: <x-hrm-command-bar active="department"> buttons </x-hrm-command-bar> --}}
@props(['active' => null])
<x-section-command-bar title="HRM" icon="dripicons-user-id" badge="Staff, attendance & payroll" :active="$active" :links="[
    ['employees', 'employees-index', 'employees.index', 'dripicons-user', __('db.Employee')],
    ['department', 'department', 'departments.index', 'dripicons-network-3', __('db.Department')],
    ['designation', 'designations', 'designations.index', 'dripicons-tag', __('db.Designation')],
    ['shift', 'shift', 'shift.index', 'dripicons-clock', __('db.Shift')],
    ['attendance', 'attendance', 'attendance.index', 'dripicons-checklist', __('db.Attendance')],
    ['holiday', 'holiday', 'holidays.index', 'dripicons-calendar', __('db.Holiday')],
    ['overtime', 'overtime', 'overtime.index', 'dripicons-hourglass', __('db.Overtime')],
    ['leave-type', 'leave-type', 'leave-type.index', 'dripicons-list', __('db.Leave Type')],
    ['leave', 'leave', 'leave.index', 'dripicons-exit', __('db.Leaves')],
    ['payroll', 'payroll', 'payroll.index', 'dripicons-wallet', __('db.Payroll')],
    ['settings', 'hrm_setting', 'setting.hrm', 'dripicons-gear', __('db.HRM Setting')],
]">{{ $slot }}</x-section-command-bar>

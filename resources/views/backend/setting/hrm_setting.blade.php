@extends('backend.layout.main')
@section('content')
<x-success-message key="message" />
<x-error-message key="not_permitted" />

<x-hrm-command-bar active="settings" />

<section class="settings-workspace-view forms">
    <div class="settings-form-card card">
        <div class="card-header d-flex align-items-center">
            <h4>{{__('db.HRM Setting')}}</h4>
        </div>
        <div class="card-body">
            <p class="italic"><small>{{__('db.The field labels marked with * are required input fields')}}.</small></p>
            {!! Form::open(['route' => 'setting.hrmStore', 'files' => true, 'method' => 'post']) !!}
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>{{__('db.Default CheckIn')}} *</label>
                            <input type="text" name="checkin" id="checkin" class="form-control" value="@if($lims_hrm_setting_data){{$lims_hrm_setting_data->checkin}}@endif" required />
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>{{__('db.Default CheckOut')}}</label>
                            <input type="text" name="checkout" id="checkout" class="form-control" value="@if($lims_hrm_setting_data){{$lims_hrm_setting_data->checkout}}@endif" required />
                        </div>
                    </div>
                    <div class="col-md-6 form-group">
                        <button type="submit" class="btn btn-primary">{{__('db.submit')}}</button>
                    </div>
                </div>
            {!! Form::close() !!}
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
    $("ul#setting").siblings('a').attr('aria-expanded','true');
    $("ul#setting").addClass("show");
    $("ul#setting #hrm-setting-menu").addClass("active");

    $('#checkin, #checkout').timepicker({
        'step': 15,

    });
</script>
@endpush

<div class="modal fade" id="otpLoginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    🔐 Login with OTP
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                {{-- SEND OTP --}}
                <form method="POST" action="{{ route('auth.login.otp.send') }}" id="otpSendForm">
                    @csrf

                   <div class="mb-3">
    <label class="form-label" style="font-weight: bold;">{{ trans('auth.phone_number') }}</label> <br>
    <input type="tel"
           id="otpPhoneInput"
           class="form-control"
           placeholder="Enter phone number"
           required>
</div>

<input type="hidden" name="phone" id="otpPhoneFull">


                    <div class="d-grid mt-3">
                        <button  id="otpBtn" class="btn btn-primary">
                            Send OTP
                        </button>
                    </div>
                </form>

                {{-- VERIFY OTP --}}
                <form method="POST"
                      action="{{ route('auth.login.otp.verify') }}"
                      id="otpVerifyForm"
                      class="mt-3"
                      style="display:none;">
                      <input type="hidden" name="phone" id="phoneForVerification">
                    @csrf

                    @include('helpers.forms.fields.text', [
                        'label' => 'OTP',
                        'name'  => 'otp',
                        'required' => true,
                    ])

                    <div class="d-grid mt-3">
                        <button class="btn btn-success">
                            Verify & Login
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>


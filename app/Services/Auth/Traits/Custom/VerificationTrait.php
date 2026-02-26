<?php
/*
 * LaraClassifier - Classified Ads Web Application
 * Copyright (c) BeDigit. All Rights Reserved
 *
 * Website: https://laraclassifier.com
 * Author: Mayeul Akpovi (BeDigit - https://bedigit.com)
 *
 * LICENSE
 * -------
 * This software is provided under a license agreement and may only be used or copied
 * in accordance with its terms, including the inclusion of the above copyright notice.
 * As this software is sold exclusively on CodeCanyon,
 * please review the full license details here: https://codecanyon.net/licenses/standard
 */

namespace App\Services\Auth\Traits\Custom;

use App\Http\Resources\PasswordResetResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Services\Auth\Traits\Custom\Verification\EmailVerificationTrait;
use App\Services\Auth\Traits\Custom\Verification\Metadata;
use App\Services\Auth\Traits\Custom\Verification\PhoneVerificationTrait;
use App\Services\Auth\Traits\Custom\Verification\VerificationExtraData;
use Illuminate\Http\JsonResponse;
use App\Models\User;

trait VerificationTrait
{
	use Metadata, EmailVerificationTrait, PhoneVerificationTrait, VerificationExtraData;
	use RecognizedUser;
	
	/**
	 * Verification
	 *
	 * Verify the user's email address or mobile phone number
	 *
	 * @param string $entityMetadataKey
	 * @param string $field
	 * @param string|null $token
	 * @param array $params
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function verifyCode(string $entityMetadataKey, string $field, string $token = null, array $params = []): JsonResponse
	{
		if (empty($token)) {
			return apiResponse()->error(trans('auth.verification_token_or_code_missing'));
		}
		
		$deviceName = $params['deviceName'] ?? null;
		
		// Get the entity metadata
		$entityMetadata = $this->getEntityMetadata($entityMetadataKey);
		
		if (empty($entityMetadata)) {
			return apiResponse()->notFound(sprintf($this->metadataNotFoundMessage, $entityMetadataKey));
		}
		
		// Get Field Label
		$fieldLabel = ($field == 'phone') ? trans('auth.phone_number') : trans('auth.email_address');
		$fieldLabel = mb_strtolower($fieldLabel);
		
		// Get Model (with its Namespace)
		$model = $entityMetadata['model'];
		
		// Verification (for Forgot Password)
		if ($entityMetadataKey == 'password') {
			return $this->verifyCodeForPassword($model, $fieldLabel, $token, $params);
		}
		
		// Get Entity by Token
		$object = $model::query()
			->withoutGlobalScopes($entityMetadata['scopes'])
			->where($field . '_token', $token)
			->first();
		
		if (empty($object)) {
			return apiResponse()->error(trans('auth.field_verification_failed', ['field' => $fieldLabel]));
		}
		
		$data = [];
		$data['result'] = null;
		
		if (empty($object->{$field . '_verified_at'})) {
			// Verified
			$object->{$field . '_verified_at'} = now();
			$object->save();
			
			$message = trans('auth.field_successfully_verified', [
				'name'  => $object->{$entityMetadata['nameColumn']},
				'field' => $fieldLabel,
			]);
			
			$data['success'] = true;
			$data['message'] = $message;
		} else {
			$message = trans('auth.field_already_verified', ['field' => $fieldLabel]);
			
			$data['success'] = false;
			$data['message'] = $message;
			
			if ($entityMetadataKey == 'users') {
				$data['result'] = new UserResource($object, $params);
			}
			if ($entityMetadataKey == 'posts') {
				$data['result'] = new PostResource($object, $params);
			}
			
			return apiResponse()->json($data);
		}
		
		// Is It User Entity?
		if ($entityMetadataKey == 'users') {
			$data['result'] = new UserResource($object, $params);
			
			// Match User's Posts (posted as Guest)
			$this->matchAuthorPosts($object);
			
			// Get User creation next URL
			// Login the User
			if (
				isVerifiedUser($object)
				&& empty($object->suspended_at)
				&& empty($object->deleted_at)
			) {
				$extra = [];
				
				if (isFromApi()) {
					// Create the API access token
					$defaultDeviceName = doesRequestIsFromWebClient() ? 'Website' : 'Other Client';
					$deviceName = $deviceName ?? $defaultDeviceName;
					$token = $object->createToken($deviceName);
					
					// Save extra data
					$extra['authToken'] = $token->plainTextToken;
					$extra['tokenType'] = 'Bearer';
				}
				
				$data['extra'] = $extra;
			}
		}
		
		// Is It Listing Entity?
		if ($entityMetadataKey == 'posts') {
			$data['result'] = new PostResource($object, $params);
			
			// Match User's listings (posted as Guest) & User's data (if missed)
			$author = $this->findPostAuthor($object);
			$this->fillMissingPostData($object, $author);
			$this->fillMissingUserData($author, $object);
		}
		
		return apiResponse()->json($data);
	}

/**
 * Verify OTP for Login (Repeatable)
 *
 * @param string $field email|phone
 * @param string $otp
 * @param array  $params
 * @return \Illuminate\Http\JsonResponse
 */
public function verifyOtpCodeForLogin(
    string $field,
    string $otp,
    array $params = []
): JsonResponse {

    if (empty($otp)) {
        return apiResponse()->error(trans('auth.verification_token_or_code_missing'));
    }

    $deviceName = $params['deviceName'] ?? null;

    /**
     * ----------------------------------
     * Find user with valid OTP
     * ----------------------------------
     */
    $user = User::query()
        ->where('phone_token', $otp)
        ->where('otp_expires_at', '>=', now())
        ->whereNull('locked_at')
        ->first();

    if (!$user) {
        return apiResponse()->error(trans('auth.invalid_or_expired_otp'));
    }

    /**
     * ----------------------------------
     * OTP Verified → Login
     * ----------------------------------
     */

    // Mark email/phone verified (only first time)
    if ($field === 'email' && empty($user->email_verified_at)) {
        $user->email_verified_at = now();
    }

    if ($field === 'phone' && empty($user->phone_verified_at)) {
        $user->phone_verified_at = now();
    }

    // Clear OTP after successful login
    $user->two_factor_otp = null;
    $user->otp_expires_at = null;
    $user->total_login_attempts = 0;
    $user->last_login_at = now();
    $user->save();

    /**
     * ----------------------------------
     * Response Data
     * ----------------------------------
     */
    $data = [];
    $data['success'] = true;
    $data['message'] = trans('auth.login_successful');
    $data['result'] = new UserResource($user, $params);

    /**
     * ----------------------------------
     * API Token (Mobile / SPA)
     * ----------------------------------
     */
        $defaultDeviceName = doesRequestIsFromWebClient()
            ? 'Website'
            : 'Mobile App';

        $deviceName = $deviceName ?? $defaultDeviceName;

        $token = $user->createToken($deviceName);

        $data['extra'] = [
            'authToken' => $token->plainTextToken,
            'tokenType' => 'Bearer',
        ];

    return apiResponse()->json($data);
}

	
	/**
	 * Verification (Forgot Password)
	 *
	 * Verify the user's email address or mobile phone number through the 'password_reset' table
	 *
	 * @param $model
	 * @param string $fieldLabel
	 * @param string|null $token
	 * @param array $params
	 * @return \Illuminate\Http\JsonResponse
	 */
	private function verifyCodeForPassword($model, string $fieldLabel, string $token = null, array $params = []): JsonResponse
	{
		// Get Entity by Token
		$object = $model::where('token', $token)->first();
		
		if (empty($object)) {
			$message = trans('auth.field_verification_failed', ['field' => $fieldLabel]);
			
			return apiResponse()->error($message);
		}
		
		$message = trans('auth.field_successfully_verified_token', ['field' => $fieldLabel]);
		
		$data = [
			'success' => true,
			'message' => $message,
			'result'  => new PasswordResetResource($object, $params),
		];
		
		return apiResponse()->json($data);
	}
}

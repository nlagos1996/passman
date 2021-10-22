<?php
/**
 * Nextcloud - passman
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Sander Brand <brantje@gmail.com>
 * @copyright Sander Brand 2016
 */

namespace OCA\Passman\Controller;

use OCA\Passman\Db\Credential;
use OCA\Passman\Service\CredentialService;
use OCA\Passman\Service\DeleteVaultRequestService;
use OCA\Passman\Service\FileService;
use OCA\Passman\Service\SettingsService;
use OCA\Passman\Service\VaultService;
use OCA\Passman\Utility\NotFoundJSONResponse;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;


class VaultController extends ApiController {
	private $userId;
	private $vaultService;
	private $credentialService;
	private $settings;
	private $fileService;
	private $deleteVaultRequestService;

	public function __construct($AppName,
	                            IRequest $request,
		$UserId,
		                        VaultService $vaultService,
		                        CredentialService $credentialService,
		                        DeleteVaultRequestService $deleteVaultRequestService,
		                        SettingsService $settings,
		                        FileService $fileService) {
		parent::__construct(
			$AppName,
			$request,
			'GET, POST, DELETE, PUT, PATCH, OPTIONS',
			'Authorization, Content-Type, Accept',
			86400);
		$this->userId = $UserId;
		$this->vaultService = $vaultService;
		$this->credentialService = $credentialService;
		$this->deleteVaultRequestService = $deleteVaultRequestService;
		$this->settings = $settings;
		$this->fileService = $fileService;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function listVaults() {
		$result = array();
		$vaults = $this->vaultService->getByUser($this->userId);

		$protected_credential_fields = array('getDescription', 'getEmail', 'getUsername', 'getPassword');
		if (isset($vaults)) {
			foreach ($vaults as $vault) {
				$credential = $this->credentialService->getRandomCredentialByVaultId($vault->getId(), $this->userId);
				$secret_field = $protected_credential_fields[array_rand($protected_credential_fields)];
				if (isset($credential)) {
					array_push($result, array(
						'vault_id' => $vault->getId(),
						'guid' => $vault->getGuid(),
						'name' => $vault->getName(),
						'created' => $vault->getCreated(),
						'public_sharing_key' => $vault->getPublicSharingKey(),
						'last_access' => $vault->getlastAccess(),
						'challenge_password' => $credential->{$secret_field}(),
						'delete_request_pending' => ($this->deleteVaultRequestService->getDeleteRequestForVault($vault->getGuid())) ? true : false
					));
				}
			}
		}

		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function create($vault_name) {
		$vault = $this->vaultService->createVault($vault_name, $this->userId);
		return new JSONResponse($vault);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function get($vault_guid) {
		$vault = null;
		try {
			$vault = $this->vaultService->getByGuid($vault_guid, $this->userId);
		} catch (\Exception $e) {
			return new NotFoundJSONResponse();
		}
		$result = array();
		if (isset($vault)) {
			$credentials = $this->credentialService->getCredentialsByVaultId($vault->getId(), $this->userId);

			$result = array(
				'vault_id' => $vault->getId(),
				'guid' => $vault->getGuid(),
				'name' => $vault->getName(),
				'created' => $vault->getCreated(),
				'private_sharing_key' => $vault->getPrivateSharingKey(),
				'public_sharing_key' => $vault->getPublicSharingKey(),
				'sharing_keys_generated' => $vault->getSharingKeysGenerated(),
				'vault_settings' => $vault->getVaultSettings(),
				'last_access' => $vault->getlastAccess(),
				'delete_request_pending' => ($this->deleteVaultRequestService->getDeleteRequestForVault($vault->getGuid())) ? true : false
			);
			$result['credentials'] = $credentials;

			$this->vaultService->setLastAccess($vault->getId(), $this->userId);
		}


		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update($vault_guid, $name, $vault_settings) {
		$vault = $this->vaultService->getByGuid($vault_guid, $this->userId);
		if ($name && $vault) {
			$vault->setName($name);
		}
		if ($vault_settings && $vault) {
			$vault->setVaultSettings($vault_settings);
		}
		$this->vaultService->updateVault($vault);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function updateSharingKeys($vault_guid, $private_sharing_key, $public_sharing_key) {
		$vault = null;
		try {
			$vault = $this->vaultService->getByGuid($vault_guid, $this->userId);
		} catch (\Exception $e) {
			// No need to catch the execption
		}

		if ($vault) {
			$this->vaultService->updateSharingKeys($vault->getId(), $private_sharing_key, $public_sharing_key);
		}

		return;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function deleteVaultContent($credential_guids, $file_ids) {
		if ($credential_guids != null && !empty($credential_guids)) {
			foreach (json_decode($credential_guids) as $credential_guid) {
				try {
					$credential = $this->credentialService->getCredentialByGUID($credential_guid, $this->userId);
					if ($credential instanceof Credential) {
						$this->credentialService->deleteCredentiaL($credential);
						$this->credentialService->deleteCredentialParts($credential, $this->userId);
					}
				} catch (\Exception $e) {
					continue;
				}
			}
		}
		if ($file_ids != null && !empty($file_ids)) {
			foreach (json_decode($file_ids) as $file_id) {
				try {
					$this->fileService->deleteFile($file_id, $this->userId);
				} catch (\Exception $e) {
					continue;
				}
			}
		}
		return new JSONResponse(array('ok' => true));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function delete($vault_guid) {
		$this->vaultService->deleteVault($vault_guid, $this->userId);
		return new JSONResponse(array('ok' => true));
	}
}

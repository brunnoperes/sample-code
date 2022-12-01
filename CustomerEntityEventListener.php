<?php

namespace App\EventListener;

use App\Entity\Customer\Customer;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use Shapeways\IdentityBundle\Client\IdentityServiceClientInterface;
use Shapeways\IdentityBundle\Security\Core\User\ProfileIdentityUser;

class CustomerEntityEventListener
{
  private $identityServiceClient;

  public function __construct(IdentityServiceClientInterface $identityServiceClient)
  {
    $this->identityServiceClient = $identityServiceClient;
  }

  public function postLoad(Customer $customer)
  {
    $identityServiceClient = $this->identityServiceClient;
    $factory = new AccessInterceptorValueHolderFactory();
    $proxy = $factory->createProxy($customer, [
      'getIdentityUser' => function () use ($customer, $identityServiceClient) {
        $customerUserId = $customer->getUserId();
        if ($customerUserId) {
          $data = $identityServiceClient->getUserByUserId($customerUserId);

          $user = new ProfileIdentityUser();
          $user->setUserId($customerUserId);
          $user->setTenantName($data->tenant_name);
          $user->setPropertyName($customer->getChannel()->getCode());
          $user->setEmailAddress($data->email_address ?? null);
          $user->setEmailAddressValidated($data->email_address_validated ?? null);

          $customer->setIdentityUser($user);
        }
      },
    ]);
    $customer->setIdentityUserProxy($proxy);
  }
}

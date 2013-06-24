<?php

final class DoorkeeperBridgeAsana extends DoorkeeperBridge {

  public function canPullRef(DoorkeeperObjectRef $ref) {
    return ($ref->getApplicationType() == 'asana') &&
           ($ref->getApplicationDomain() == 'asana.com') &&
           ($ref->getObjectType() == 'asana:task');
  }

  public function pullRefs(array $refs) {

    $id_map = mpull($refs, 'getObjectID', 'getObjectKey');
    $viewer = $this->getViewer();

    $provider = PhabricatorAuthProviderOAuthAsana::getAsanaProvider();
    if (!$provider) {
      return;
    }

    $accounts = id(new PhabricatorExternalAccountQuery())
      ->setViewer($viewer)
      ->withUserPHIDs(array($viewer->getPHID()))
      ->withAccountTypes(array($provider->getProviderType()))
      ->withAccountDomains(array($provider->getProviderDomain()))
      ->execute();

    if (!$accounts) {
      return;
    }

    // TODO: If the user has several linked Asana accounts, we just pick the
    // first one arbitrarily. We might want to try using all of them or do
    // something with more finesse. There's no UI way to link multiple accounts
    // right now so this is currently moot.
    $account = head($accounts);

    $token = $provider->getOAuthAccessToken($account);
    if (!$token) {
      return;
    }

    $template = id(new PhutilAsanaFuture())
      ->setAccessToken($token);

    $futures = array();
    foreach ($id_map as $key => $id) {
      $futures[$key] = id(clone $template)
        ->setRawAsanaQuery("tasks/{$id}");
    }

    $results = array();
    foreach (Futures($futures) as $key => $future) {
      try {
        $results[$key] = $future->resolve();
      } catch (Exception $ex) {
        // TODO: For now, ignore this stuff.
      }
    }

    foreach ($refs as $ref) {
      $ref->setAttribute('name', pht('Asana Task %s', $ref->getObjectID()));

      $result = idx($results, $ref->getObjectKey());
      if (!$result) {
        continue;
      }

      $ref->setIsVisible(true);
      $ref->setAttribute('asana.data', $result);
      $ref->setAttribute('fullname', pht('Asana: %s', $result['name']));
      $ref->setAttribute('title', $result['name']);
      $ref->setAttribute('description', $result['notes']);

      $obj = $ref->getExternalObject();
      if ($obj->getID()) {
        continue;
      }

      $id = $result['id'];
      $uri = "https://app.asana.com/0/{$id}/{$id}";
      $obj->setObjectURI($uri);

      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
        $obj->save();
      unset($unguarded);
    }
  }

}

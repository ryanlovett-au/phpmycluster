<?php

use App\Livewire\ClusterSetupWizard;
use Livewire\Livewire;

it('allows an approved user to view the setup wizard', function () {
    $user = createApprovedUser();

    $this->actingAs($user)
        ->get(route('cluster.create'))
        ->assertOk();
});

it('starts at step 1', function () {
    $user = createApprovedUser();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->assertSet('step', 1);
});

it('advances the step with nextStep', function () {
    $user = createApprovedUser();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'test-cluster')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->call('nextStep')
        ->assertSet('step', 2);
});

it('goes back with previousStep', function () {
    $user = createApprovedUser();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'test-cluster')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->call('previousStep')
        ->assertSet('step', 1);
});

it('generates an SSH keypair with generateSshKey', function () {
    $user = createApprovedUser();

    $component = Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->call('generateSshKey');

    $keyPair = $component->get('generatedKeyPair');

    expect($keyPair)->toBeArray()
        ->and($keyPair)->toHaveKeys(['private', 'public'])
        ->and($keyPair['private'])->toContain('PRIVATE KEY')
        ->and($keyPair['public'])->toStartWith('ssh-');
});

it('has required fields for cluster name and seed host', function () {
    $user = createApprovedUser();

    // Trying to advance without filling required fields should fail validation
    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->call('nextStep')
        ->assertHasErrors(['clusterName', 'clusterAdminPassword']);
});

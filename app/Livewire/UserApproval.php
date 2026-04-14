<?php

namespace App\Livewire;

use App\Models\User;
use Livewire\Component;

class UserApproval extends Component
{
    public string $actionMessage = '';

    public string $actionStatus = '';

    public function approve(int $userId): void
    {
        $this->authorise();

        $user = User::findOrFail($userId);

        if ($user->isApproved()) {
            $this->setMessage(__(':name is already approved.', ['name' => $user->name]), 'info');

            return;
        }

        $user->approve(auth()->user());
        $this->setMessage(__(':name has been approved.', ['name' => $user->name]), 'success');
    }

    public function revoke(int $userId): void
    {
        $this->authorise();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->setMessage(__('You cannot revoke your own approval.'), 'error');

            return;
        }

        $user->update([
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $this->setMessage(__(':name\'s approval has been revoked.', ['name' => $user->name]), 'success');
    }

    public function toggleAdmin(int $userId): void
    {
        $this->authorise();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->setMessage(__('You cannot change your own admin status.'), 'error');

            return;
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        $status = $user->is_admin ? 'granted' : 'removed';
        $this->setMessage(__("Admin access {$status} for :name.", ['name' => $user->name]), 'success');
    }

    public function deleteUser(int $userId): void
    {
        $this->authorise();

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->setMessage(__('You cannot delete your own account.'), 'error');

            return;
        }

        $user->delete();
        $this->setMessage(__('User deleted.'), 'success');
    }

    protected function authorise(): void
    {
        abort_unless(auth()->user()->is_admin, 403);
    }

    protected function setMessage(string $message, string $status): void
    {
        $this->actionMessage = $message;
        $this->actionStatus = $status;
    }

    public function render()
    {
        abort_unless(auth()->user()->is_admin, 403);

        return view('livewire.user-approval', [
            'pendingUsers' => User::whereNull('approved_at')->get(),
            'approvedUsers' => User::whereNotNull('approved_at')->get(),
        ])->layout('layouts.app', ['title' => __('User Management')]);
    }
}

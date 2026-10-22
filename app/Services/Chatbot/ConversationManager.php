<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationState;
use App\Models\Conversation;

class ConversationManager
{
    public function isValidTime(string $time): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!H:i', $time);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('H:i') === $time;
    }

    public function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    public function isAwaitingInput(Conversation $conversation): bool
    {
        return $conversation->getContextValue('awaiting') !== null;
    }

    public function getAwaiting(Conversation $conversation): ?string
    {
        return $conversation->getContextValue('awaiting');
    }

    public function setAwaiting(Conversation $conversation, string $key): void
    {
        $conversation->setContextValue('awaiting', $key);
        $conversation->state = ConversationState::IN_PROGRESS;
    }

    public function clearAwaiting(Conversation $conversation): void
    {
        $conversation->setContextValue('awaiting', null);
    }

    public function getSlot(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        return $conversation->getContextValue("slots.{$key}", $default);
    }

    public function setSlot(Conversation $conversation, string $key, mixed $value): void
    {
        $conversation->setContextValue("slots.{$key}", $value);
    }

    public function finishFlow(Conversation $conversation): void
    {
        $conversation->clearContext();
        $conversation->state = ConversationState::IDLE;
    }

    public function escalateToHuman(Conversation $conversation): void
    {
        $conversation->clearContext();
        $conversation->state = ConversationState::HANDOFF_TO_HUMAN;
    }

    public function close(Conversation $conversation): void
    {
        $conversation->clearContext();
        $conversation->state = ConversationState::CLOSED;
    }

    public function reopenIfClosed(Conversation $conversation): void
    {
        if ($conversation->state === ConversationState::CLOSED) {
            $conversation->state = ConversationState::IDLE;
            $conversation->assigned_agent_id = null;
            $conversation->assigned_at = null;
            $conversation->closed_at = null;
            $conversation->closed_by = null;
        }
    }
}

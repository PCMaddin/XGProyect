<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Entity;

/**
 * @phpstan-import-type Column from Entity
 *
 * @deprecated v4.0.0 use laravel instead
 */
class BuddyEntity extends Entity
{
    /**
     * Return the buddy id
     *
     * @return Column
     */
    public function getBuddyId()
    {
        return $this->data['buddy_id'];
    }

    /**
     * Return the buddy sender
     *
     * @return Column
     */
    public function getBuddySender()
    {
        return $this->data['buddy_sender'];
    }

    /**
     * Return the buddy receiver
     *
     * @return Column
     */
    public function getBuddyReceiver()
    {
        return $this->data['buddy_receiver'];
    }

    /**
     * Return the buddy status
     *
     * @return Column
     */
    public function getBuddyStatus()
    {
        return $this->data['buddy_status'];
    }

    /**
     * Return the buddy request text
     *
     * @return Column
     */
    public function getRequestText()
    {
        return $this->data['buddy_request_text'];
    }
}

<?php

namespace Core\Domain\Model\Service;

use Core\Domain\Model\User\User;
use Core\Domain\Model\Tarif\Tarif;
use Core\Domain\Model\DomainLogicException;

use \DateInterval;
use \DateTime;

class Service
{
    const LATENCY_PERIOD = 10;
    const FORBIDDEN_DAYS = array(29, 30, 31);
    const TARIF_GROUP_IDS = array(1);

    private int $id;
    private User $user;
    private int $groupId;
    private Tarif $tarif;
    private DateTime $payday;
    private bool $paidFor;

    public function __construct(
        int $id,
        User $user,
        int $groupId,
        Tarif $tarif,
        DateTime $payday,
        bool $paidFor
    ) {
        $this->id = $id;
        $this->user = $user;
        $this->groupId = $groupId;
        $this->tarif = $tarif;
        $this->payday = $payday;
        $this->paidFor = $paidFor;
    }

    public static function latencyPeriod(): DateInterval
    {
        return new DateInterval(
            'P' . self::LATENCY_PERIOD . 'D'
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function groupId(): int
    {
        return $this->groupId;
    }

    public function tarif(): Tarif
    {
        return $this->tarif;
    }

    public function payday(): DateTime
    {
        return $this->payday;
    }

    public function paidFor(): bool
    {
        return $this->paidFor;
    }


    public function startTarif(Tarif $newTarif): void
    {
        // Checking logic
        if (!$this->tarif->isInactive()) {
            throw new DomainLogicException(
                sprintf(
                    'The service with correspondent serviceId = %d already has a tarif.',
                    $this->id
                )
            );
        }
        if (!$this->groupIdsMatch($newTarif)) {
            throw new DomainLogicException(
                'This tarif cannot be set at this service (groupIds don\'t match).'
            );
        }
        /** @throws DomainLogicException */
        $this->tarif->compareWithNew($newTarif);

        // Updating User
        $this->user->balance()->subtract($newTarif->price());

        // Updating Service
        $paidFor = $this->user->balance()->isPositive();
        if ($this->longInactive()) {
            $payday = $this->addDaysIfNecessary(new DateTime('today'))
                ->add($newTarif->payPeriod());
        } else {
            $payday = $this->addDaysIfNecessary($this->payday)
                ->add($newTarif->payPeriod());
        }
        $this->payday = $payday;
        $this->paidFor = $paidFor;
        $this->tarif = $newTarif;

        // Updating User Access
        $this->user->updateAccessGranted();
    }

    private function groupIdsMatch(Tarif $tarif): bool
    {
        return $this->groupId == $tarif->groupId();
    }

    
    private function longInactive(): bool
    {
        return (new DateTime('today'))
            ->sub(self::latencyPeriod())
            ->diff($this->payday)
            ->invert;
    }

    private function addDaysIfNecessary(DateTime $datetime): DateTime
    {
        if (in_array($datetime->format('d'), self::FORBIDDEN_DAYS)) {
            $datetime->modify('first day of next month');
        }

        return $datetime;
    }
    

    public function stopTarif(): void
    {
        // Checking Logic
        if ($this->tarif->isInactive()) {
            throw new DomainLogicException(
                sprintf(
                    'The service with serviceId = %d does not have a tarif.',
                    $this->id
                )
            );
        }

        // Updating User Balance
        $this->user->balance()->add(
            $this->calculateChangeForStop()
        );

        // Updating Service
        $this->payday = $this->newPayday();
        $this->paidFor = false;
        $this->tarif = Tarif::inactiveTarif();

        // Updating User Access
        $this->user->updateAccessGranted();
    }

    /**
     *@TODO
     * the change should be calculated for days left, not for days used
     * Example: started tarif on 27.04, stopped on 17.05, daysUsed = 20
     */
    private function calculateChangeForStop(): float
    {
        if ($this->paidFor) {
            $daysUsed = (clone $this->payday)
                ->sub($this->tarif->payPeriod())
                ->diff(new DateTime('tomorrow'))
                ->days;
            $change = $this->tarif->price() - ($this->tarif->basePricePerDay() * $daysUsed);
            return ($change > 0.0) ? $change : 0.0;

        //if the user didn't pay for the tarif but used credit access
        } else if ($this->creditAccessWasUsed()) {
            // Update balance: add price and sub days which were used
            return 
                $this->tarif->price() 
                - (
                    $this->tarif->basePricePerDay()
                    * $this->creditAccess->daysUsed()
                );

        //if the user didn't pay for the tarif and didn't take credit access in the last period
        } else {
            // Update balance: add price
            return $this->tarif->price();
        }

    }

    private function creditAccessWasUsed(): bool
    {
        // maybe there is no need to check canTake() since user didn't pay 
        // (canTake() always returns false)
        return !$this->user->creditAccess()->canTake()
            && (
                (clone $this->payday)
                ->sub(
                    $this->tarif->payPeriod()
                ) 
                < $this->user->creditAccess()->activeUntil()
            );
    }

    private function newPayday(): DateTime
    {
        //if the user paid for the current tarif
        // or if the user used credit access
        if ($this->paidFor
            || $this->creditAccessWasUsed()
        ) {
            return new Datetime('tomorrow');
        }
        //the user didn't pay for the tarif and didn't take credit access in the last period
        return $this->payday->sub(
            $this->tarif()->payPeriod()
        );
    }

    public function changeTarif(Tarif $newTarif): void
    {
        //Checking Logic
        if ($this->tarif->isInactive()) {
            throw new DomainLogicException(
                "This service does not have a tarif."
            );
        };
        if (!$this->groupIdsMatch($newTarif)) {
            throw new DomainLogicException(
                "This tarif cannot be set at this service (groupIds don't match)."
            );
        };
        $this->tarif->compareWithNew($newTarif);

        // Updating User
        $this->user->balance()->subtract(
            $this->calculateChange($newTarif)
        );
        $this->user->updateAccessGranted(); //maybe better put it in the end

        // Updating Service
        $this->payday = $this->payday
                ->sub($this->tarif->payPeriod())
                ->add($newTarif->payPeriod());
        $this->tarif = $newTarif;
    }

    private function calculateChange($newTarif): float
    {
        $firstPartInDays = (new DateTime('today'))
            ->diff($this->payday)
            ->days;

        $secondPartInDays = (
            $newTarif->payPeriod()->m 
            - $this->tarif->payPeriod()->m
        ) * 30;

        $firstPart = 
            (
                $newTarif->pricePerDay() 
                - $this->tarif->pricePerDay()
            ) 
            * $firstPartInDays;

        $secondPart = $newTarif->pricePerDay() * $secondPartInDays;

        return $firstPart + $secondPart;
    }

    public function showAvaliableTarifs(array $tarifs): array
    {
        $data = [];

        foreach ($tarifs as $tarif) {
            if (
                !$this->tarif->isCompatibleWithNew($tarif)
                || !$this->groupIdsMatch($tarif)
            ) {
                continue;
            }

            $data[] = $tarif->info();
        }

        return $data;
    }

    public function info(): array
    {
        return [
            'id' => $this->id(),
            'group_id' => $this->groupId,
            'tarif_info' => $this->tarif->info(),
            'payday' => $this->payday->format('Y-m-d'),
            'paid_for' => $this->paidFor
        ];
    }
}

<?php

class TarifDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        // стоимость в рублях
        public readonly int $price,
        // срок действия в месяцах
        public readonly int $duration,
        // скорость в МБит/с
        public readonly int $speed,
        public readonly string $type,
    ) {
    }
}

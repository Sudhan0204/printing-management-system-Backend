<?php

/**
 * Shared bill line calculation used by the API (and mirrored in the frontend).
 * Backend always recalculates — never trust amounts from React.
 */
class BillingCalc
{
    public const UNITS = ["sq_ft", "piece", "meter", "kg", "roll", "other"];
    public const PRICING_TYPES = ["dimension_based", "fixed_price", "quantity_based"];

    public static function calculateLine($pricingType, $width, $height, $quantity, $rate)
    {
        $pricingType = (string) $pricingType;
        $quantity = (float) $quantity;
        $rate = (float) $rate;
        $width = $width === null || $width === "" ? null : (float) $width;
        $height = $height === null || $height === "" ? null : (float) $height;

        if ($quantity <= 0) {
            throw new InvalidArgumentException("Quantity must be greater than zero");
        }

        if ($rate < 0) {
            throw new InvalidArgumentException("Rate cannot be negative");
        }

        if ($pricingType === "dimension_based") {
            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                throw new InvalidArgumentException("Width and height are required for dimension-based pricing");
            }

            $area = round($width * $height * $quantity, 4);
            $amount = round($area * $rate, 2);

            return [
                "width" => $width,
                "height" => $height,
                "quantity" => $quantity,
                "rate" => $rate,
                "area" => $area,
                "amount" => $amount,
            ];
        }

        if (!in_array($pricingType, ["fixed_price", "quantity_based"], true)) {
            throw new InvalidArgumentException("Invalid pricing type");
        }

        $amount = round($rate * $quantity, 2);

        return [
            "width" => null,
            "height" => null,
            "quantity" => $quantity,
            "rate" => $rate,
            "area" => null,
            "amount" => $amount,
        ];
    }

    public static function unitLabel($unit)
    {
        $map = [
            "sq_ft" => "Sq.ft",
            "piece" => "Piece",
            "meter" => "Meter",
            "kg" => "Kg",
            "roll" => "Roll",
            "other" => "Other",
        ];

        return $map[$unit] ?? $unit;
    }

    public static function pricingLabel($type)
    {
        $map = [
            "dimension_based" => "Dimension Based",
            "fixed_price" => "Fixed Price",
            "quantity_based" => "Quantity Based",
        ];

        return $map[$type] ?? $type;
    }
}

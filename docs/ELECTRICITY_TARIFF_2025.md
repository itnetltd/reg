# REG electricity bill estimator tariff baseline

**Effective date:** 1 October 2025  
**Calculation basis:** VAT and regulatory fees exclusive.

The estimator implements the tariff schedule supplied for the REG website project.

## Progressive monthly blocks

- Residential: 0–20 kWh at 89 RWF/kWh; above 20–50 kWh at 310 RWF/kWh; above 50 kWh at 369 RWF/kWh.
- Non-residential: 0–100 kWh at 355 RWF/kWh; above 100 kWh at 376 RWF/kWh.

## Flat all-energy tariffs

- Telecom towers: 289 RWF/kWh.
- Hotels below 660,000 kWh/year: 239 RWF/kWh.
- Hotels above 660,000 kWh/year: 175 RWF/kWh.
- Health facilities: 214 RWF/kWh.
- Schools and higher-learning institutions: 214 RWF/kWh.
- Broadcasters below 660,000 kWh/year: 276 RWF/kWh.
- Shared broadcasting infrastructure at least 660,000 kWh/year: 110 RWF/kWh.
- Commercial data centres: 175 RWF/kWh.
- Water pumping stations: 133 RWF/kWh.
- Water treatment plants: 133 RWF/kWh.
- Public electric charging infrastructure: 110 RWF/kWh.
- Steel, mining and cement industries at least 1,000,000 kWh/year: 97 RWF/kWh.

## Industrial prepaid customers without smart meters

- Small: 175 RWF/kWh.
- Medium: 156 RWF/kWh.
- Large: 124 RWF/kWh.

## Industrial postpaid customers with smart meters

The total is energy consumption multiplied by the energy rate, plus maximum-demand charges for peak and shoulder periods. The supplied off-peak maximum-demand rate is zero.

| Category | Energy RWF/kWh | Peak RWF/kVA/month | Off-peak RWF/kVA/month | Shoulder RWF/kVA/month |
|---|---:|---:|---:|---:|
| Small | 175 | 11,017 | 0 | 4,008 |
| Medium | 133 | 10,514 | 0 | 3,588 |
| Large | 110 | 7,184 | 0 | 2,004 |
| Steel, mining and cement ≥1,000,000 kWh/year | 97 | 7,184 | 0 | 2,004 |

## Important limitation

VAT and regulatory-fee percentages were not included in the supplied schedule. The estimator therefore reports only tariff energy and demand charges and clearly marks the output as VAT and regulatory-fee exclusive.

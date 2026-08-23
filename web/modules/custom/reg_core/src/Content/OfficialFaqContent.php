<?php

namespace Drupal\reg_core\Content;

/**
 * Migration source for the official REG FAQ content supplied for import.
 */
final class OfficialFaqContent {

  public const SOURCE_LABEL = 'REG Official FAQ';

  public const SOURCE_URL = 'https://www.reg.rw/public-information/faqs/';

  /**
   * Returns the 36 reviewed FAQ source records.
   *
   * Content in this class is copied into Drupal entities by the importer. It
   * is never used directly by the public Twig templates or search endpoint.
   *
   * @return array<int, array<string, mixed>>
   *   FAQ migration records.
   */
  public static function records(): array {
    $online = ['uri' => 'https://online.reg.rw/', 'title' => 'OPEN ONLINE SERVICES'];
    $branches = ['uri' => 'internal:/branches', 'title' => 'FIND A BRANCH'];
    $outages = ['uri' => 'internal:/outages', 'title' => 'CHECK POWER OUTAGES'];
    $complaints = ['uri' => 'internal:/report-problem', 'title' => 'REPORT A PROBLEM'];
    $estimator = ['uri' => 'internal:/tools/bill-estimator', 'title' => 'ESTIMATE MY BILL'];
    $tariffs = ['uri' => 'https://www.reg.rw/customer-service/tariffs/', 'title' => 'VIEW CURRENT TARIFFS'];
    $connection = ['uri' => 'https://www.reg.rw/customer-service/new-connection-process/', 'title' => 'VIEW NEW CONNECTION PROCESS'];

    return [
      self::record(1, 'What is REG?',
        'REG is Rwanda Energy Group. It’s a holding company in charge of energy in Rwanda. It has 2 subsidiary companies which are EDCL (Energy Development Corporation Ltd.) in charge of energy development projects and EUCL (Energy Utility Corporation Limited) in charge of utility and maintenance.',
        'about_reg', 'REG, Rwanda Energy Group, EDCL, EUCL, energy company, subsidiaries', 20),
      self::record(2, 'What is the difference between REG, EDCL and EUCL?',
        'REG is a holding company. It has a role to supervise and monitor its two subsidiary companies, EDCL and EUCL. These two subsidiaries are the ones that deal with operations and services. EDCL is mainly into development projects while EUCL is the one that does the commercial activities and maintenance. When customers have electricity issues, EUCL is the one that intervenes.',
        'about_reg', 'REG, EDCL, EUCL, subsidiary, development projects, utility, maintenance, electricity issues', 20),
      self::record(3, 'Where are your offices?',
        'REG and EUCL headquarters are in Kigali, near SULFO Headquarters. EDCL is located in Kigali City Tower in the city center. REG also has branches across the country.',
        'branches', 'branch, office, headquarters, contact, district, Kigali, REG office, EDCL office, EUCL office', 8, [$branches], 'branches', 'needs_review',
        'Branch and headquarters details must be validated against the Drupal Branch Locator before production.',
        'REG and EUCL headquarters are in Kigali, near SULFO Headquarters. EDCL is located in Kigali City Tower in the city center. We also have branches all over the country. In every District, there is one branch of EUCL except in Kigali City where there are 3 additional branches due to the bigger number of customers.'),
      self::record(4, 'How can I contact REG?',
        'You can call the REG toll free number 2727 and get in touch with the call center. You can also use the Branch Locator to find branch phone numbers and email contacts.',
        'branches', 'contact REG, 2727, toll free, call center, branch, office, phone, email, district', 1, [$branches], 'call_center'),
      self::record(5, 'How can I request a new connection?',
        "A customer can request a new connection online or using the approved application process.\n\nThe customer must:\n- Fill the Service Application Form and provide the required information.\n- Provide necessary attachments such as ID card/passport and proof of ownership/land title.\n- Make full payment or agree on payment in instalments according to the new connection policy.\n- Provide any other information REG may require.",
        'connection', 'new connection, connection, application, electricity connection, connect house, application form, ID, passport, land title, ownership, instalments', 2, [$online, $connection]),
      self::record(6, 'What are the prices for residential households?',
        "For residential customers:\n- The first 20 kWh per month are charged at 89 RWF/kWh.\n- Consumption above 20 kWh up to 50 kWh is charged at 310 RWF/kWh.\n- Consumption above 50 kWh is charged at 369 RWF/kWh.\n\nThe rates are VAT and regulatory-fee exclusive.",
        'tariffs', 'tariff, price, electricity price, residential tariff, household, unit price, kWh, bill, 89, 310, 369, October 2025', 2, [$tariffs, $estimator]),
      self::record(7, 'How should I pay REG for a service?',
        "Payment is only made through official REG/EUCL/EDCL payment channels and accounts. No cash payment to staff is allowed.\n\nIf a staff member requests cash payment, report the case through approved REG reporting channels.",
        'payments', 'payment, official account, cash payment, pay REG, staff, corruption, unofficial payment', 6, [$complaints], '', 'needs_review',
        'Confirm the current anti-corruption reporting number before final publication.',
        'Payment is only done on REG/EUCL/EDCL accounts. No cash payment is allowed. If one of our employees requests a cash payment, the official source instructs customers to report it using numbers shown on that source page.'),
      self::record(8, 'Is it allowed to give some facilitation to REG staff after receiving a service?',
        "No. Facilitation or unofficial payment to REG/EUCL/EDCL staff is not allowed and may constitute corruption.\n\nUse official REG reporting channels if a member of staff requests an unofficial payment.",
        'anti_corruption', 'facilitation, corruption, bribe, unofficial payment, staff payment, report corruption', 8, [$complaints], '', 'needs_review',
        'Do not publish a corruption-reporting number until REG confirms the current channel.',
        'The official FAQ states that facilitation is not allowed and asks customers to report requests for facilitation using a number displayed on the source page.'),
      self::record(9, 'Why do we sometimes have power cuts?',
        "Power interruptions can result from network faults, maintenance, upgrades, overloading, construction work or other technical causes.\n\nCustomers should check the REG Outage Center for current and planned interruptions.",
        'outages', 'power cut, power outage, no electricity, interruption, blackout, maintenance, network fault, planned outage', 2, [$outages]),
      self::record(10, 'How can I know my due arrears?',
        'Please use the approved REG Online Services platform or contact your respective branch or call 2727 for assistance with account arrears.',
        'customer_accounts', 'arrears, balance, account, unpaid bill, due amount, customer account, online services, branch, 2727', 2, [$online, $branches], 'call_center', 'needs_review',
        'The historical source says an arrears system was being developed. Confirm the current Online Services capability.',
        'The official FAQ says customers may contact their branch or call 2727 and refers to an online arrears system being developed.'),
      self::record(11, 'I have a token but it is not entering in the meter. What should I do?',
        'Contact your respective branch or call the REG toll-free number 2727 for technical assistance.',
        'electricity_tokens', 'token not working, token not entering, meter token, cash power, prepaid, electricity units, technical assistance', 1, [$branches], 'call_center'),
      self::record(12, 'I am trying to buy electricity units but I get a message that my cash power is not registered. What should I do?',
        'Contact your respective branch or call REG on 2727 for assistance.',
        'prepaid_meter', 'cash power not registered, prepaid not registered, buy electricity, electricity units, meter registration', 3, [$branches], 'call_center'),
      self::record(13, 'What should I do if I find a cash power bypass?',
        'Contact the nearest REG branch or call 2727 so the case can be handled by the responsible team.',
        'electricity_theft', 'cash power bypass, meter bypass, electricity theft, unpaid electricity, report bypass', 8, [$branches], 'call_center'),
      self::record(14, 'How is the new electricity tariff structured?',
        'See the current approved Electricity End-User Tariff and use the REG Bill Estimator for an indicative calculation.',
        'tariffs', 'new tariff, tariff structure, electricity tariff, electricity price, kWh, bill estimator, October 2025', 3, [$tariffs, $estimator]),
      self::record(15, 'I am unable to buy electricity. What should I do?',
        'If you are unable to purchase electricity through the approved payment channels, contact your respective branch or call REG on 2727 to check whether there is an issue with your meter or account.',
        'payments', 'unable to buy electricity, cannot buy power, payment channel, meter issue, account issue, cash power, prepaid', 1, [$online, $branches], 'call_center', 'needs_review',
        'REG must validate every current payment channel before specific USSD or bank options are published.',
        'The official FAQ lists historical USSD, mobile-money, bank, post-office and application purchasing options, then advises contacting the branch or 2727 if all options fail.'),
      self::record(16, 'I already paid for electricity but I am not receiving the token. What should I do?',
        'First contact the telecommunications company, bank or payment channel used for the transaction. If the issue is not resolved, contact REG on 2727.',
        'electricity_tokens', 'paid no token, token not received, missing token, payment channel, bank, telecom, electricity units', 1, [], 'call_center'),
      self::record(17, 'What should I do to get my cash power meter replaced?',
        'Visit or contact your respective branch or call REG on 2727.',
        'meter_problems', 'replace cash power, meter replacement, prepaid meter, faulty meter, branch, 2727', 3, [$branches], 'call_center'),
      self::record(18, 'It seems like my meter is running faster. What should I do?',
        'Contact your nearest branch or call REG on 2727 for assistance.',
        'meter_problems', 'meter running fast, high consumption, faulty meter, cash power, prepaid meter, branch', 3, [$branches], 'call_center'),
      self::record(19, 'It seems like my meter is recorded in the wrong category. What should I do?',
        'Contact your local REG branch or call 2727 so your customer category can be checked.',
        'meter_problems', 'wrong meter category, customer category, tariff category, meter record, account category', 4, [$branches], 'call_center'),
      self::record(20, 'I need to shift from prepaid to post-paid meter. What should I do?',
        'Eligibility for changing from a prepaid to a post-paid meter depends on the applicable customer and consumption requirements. Contact your nearest REG branch or call 2727 for current guidance.',
        'meter_problems', 'prepaid to postpaid, change meter, meter conversion, eligibility, consumption, cash power', 10, [$branches], 'call_center', 'needs_review',
        'REG must technically validate the current eligibility threshold. The historical 300A statement is not published.',
        'The official FAQ says eligibility depends on customer consumption and states a historical 300A condition.'),
      self::record(21, 'My meter was stolen. What should I do?',
        'Report the incident immediately to your nearest REG branch or call 2727.',
        'meter_problems', 'meter stolen, stolen cash power, stolen prepaid meter, report meter, branch, 2727', 2, [$branches], 'call_center'),
      self::record(22, 'My residence is consuming electricity while my meter is off. What should I do?',
        'Contact your local REG branch or call 2727 immediately so the installation can be checked.',
        'meter_problems', 'meter off, electricity still on, unpaid electricity, installation check, meter problem, safety', 5, [$branches], 'call_center'),
      self::record(23, 'We usually lose electricity during peak hours. What should we do?',
        'Check the Outage Center first. If no relevant interruption is published, contact your local branch or call 2727 for assistance.',
        'outages', 'peak hours, lose electricity, power cut, power outage, no electricity, interruption, blackout, voltage', 1, [$outages, $branches], 'call_center'),
      self::record(24, 'What are the requirements for title transfer after buying a house with an electricity connection?',
        'Present a letter requesting title transfer and attach a current copy of the land title and a copy of the identification document.',
        'connection_changes', 'title transfer, buy house, electricity connection, land title, identification, ID, customer name change', 12, [$branches], '', 'needs_review',
        'REG should confirm whether additional title-transfer documents are now required.'),
      self::record(25, 'How can my household be connected when I live far from an electricity line?',
        'Contact your local REG branch for technical advice and assistance.',
        'connection', 'far from electricity line, connect household, line extension, new connection, technical advice', 8, [$branches]),
      self::record(26, 'The toll-free line is not easily accessible. What other support channels can I use?',
        'Use the REG Branch Locator, official contact channels or approved REG social media channels.',
        'branches', 'toll free unavailable, other support, contact REG, branch locator, social media, customer support', 8, [$branches]),
      self::record(27, 'What can I do if I receive poor service from the toll-free line 2727?',
        "Use REG's official customer complaint channels or contact another REG support channel.",
        'complaints', 'poor service, toll free complaint, 2727 complaint, call center complaint, customer service complaint', 7, [$complaints]),
      self::record(28, 'How can I report suspected electricity theft or unpaid electricity use?',
        'Call REG on 2727 or report the case to the nearest REG branch.',
        'electricity_theft', 'electricity theft, unpaid power, meter bypass, report theft, illegal connection, neighbour', 7, [$branches], 'call_center'),
      self::record(29, 'How many days does it take to get a new connection after I have fulfilled all requirements?',
        "Connection time depends on the connection category and infrastructure conditions. The current REG New Connection Process lists LV connections and extensions up to 100 metres within 4 working days, LV extensions over 100 metres up to 800 metres within 10 working days, and the listed MV connections and extensions within 20 working days. Consult the current process page for the timeframe that applies to your connection.",
        'connection', 'new connection time, connection duration, working days, residential connection, non-residential, LV, MV, connection matrix', 5, [$connection, $online], '', 'verified',
        'Validated against the current REG New Connection Process matrix during import.',
        'The official FAQ states approximately 4–10 working days for residential connections and 10–20 working days for non-residential connections.'),
      self::record(30, 'My meter became faulty while I still had electricity units. How can those units be transferred to the new meter?',
        'Contact your local REG branch for assistance.',
        'meter_problems', 'faulty meter, transfer units, new meter, electricity units, cash power, prepaid meter', 4, [$branches]),
      self::record(31, 'I am a tenant and need my own meter because several people are using one meter. Is it possible?',
        'The official FAQ advises requesting the property owner to apply for a supplementary meter.',
        'connection', 'tenant meter, own meter, supplementary meter, property owner, shared meter, new connection', 12, [$branches], '', 'needs_review',
        'REG should confirm the current supplementary-meter requirements.'),
      self::record(32, 'There is an electrical pole or cable crossing my land. What should I do?',
        'Contact your local REG branch for advice and assistance.',
        'safety', 'electrical pole, cable crossing land, network, safety, land, branch, power line', 8, [$branches]),
      self::record(33, 'I bought electricity using the wrong meter number. What can I do?',
        'Contact the nearest REG branch for assistance.',
        'electricity_tokens', 'wrong meter number, bought electricity, wrong token, refund token, electricity units', 3, [$branches]),
      self::record(34, 'I want to be disconnected and no longer use electricity. What should I do?',
        'Contact your local REG branch for contract termination guidance.',
        'connection_changes', 'disconnect electricity, stop service, contract termination, close account, branch', 10, [$branches]),
      self::record(35, 'I purchased electricity but did not receive the token. What should I do?',
        'Contact REG on 2727 with the relevant meter details, or use another approved REG customer-support channel.',
        'electricity_tokens', 'purchased electricity, token not received, missing token, meter details, cash power, prepaid', 1, [$branches], 'call_center'),
      self::record(36, 'How can I check my meter number?',
        "The procedure depends on the meter type.\n\nCurrent official FAQ lists:\n- EDMI: Dial 08 and Enter\n- Star: Dial 075 and Enter\n- Landis: Dial i000 or i025\n- Conlog Combo: Dial #04#\n- Actaris Combo: Dial 65 and Enter\n- Split Conlog: Dial #100#\n- Positivo: Dial 65 and Enter\n- Ningbo: Dial 100 and Enter\n- Sunrise: Dial 100 and Enter\n- Hexing: Dial 804 and Enter\n\nIf the relevant code does not work, contact REG on 2727.",
        'prepaid_meter', 'meter number, check meter number, EDMI, Star, Landis, Conlog, Actaris, Positivo, Ningbo, Sunrise, Hexing, cash power', 1, [], 'call_center', 'needs_review',
        'Confirm every code against the meter models currently deployed before production.'),
    ];
  }

  /**
   * Builds one normalized source record.
   */
  private static function record(
    int $number,
    string $question,
    string $answer,
    string $category,
    string $keywords,
    int $priority,
    array $actions = [],
    string $escalation = '',
    string $reviewStatus = 'verified',
    string $reviewNotes = '',
    string $sourceExcerpt = '',
  ): array {
    return [
      'source_id' => sprintf('reg_official_faq_%03d', $number),
      'question' => $question,
      'answer' => $answer,
      'category' => $category,
      'keywords' => $keywords,
      'priority' => $priority,
      'actions' => $actions,
      'escalation' => $escalation,
      'review_status' => $reviewStatus,
      'review_notes' => $reviewNotes,
      'source_excerpt' => $sourceExcerpt !== '' ? $sourceExcerpt : $answer,
    ];
  }

}

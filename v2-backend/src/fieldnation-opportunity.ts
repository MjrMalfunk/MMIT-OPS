import { parseFieldNationSchedule } from './fieldnation-schedule.js';
import { calculateFieldNationFees, fieldNationFeeSchedule } from './fieldnation-fees.js';
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';

export type FieldNationOpportunity = {
  sourceReference: string | null;
  title: string | null;
  buyerName: string;
  opportunityStatus: 'AVAILABLE' | 'ROUTED' | 'ASSIGNED' | 'DECLINED' | 'MESSAGE';
  location: string | null;
  scheduledAt: Date | null;
  grossPay: string | null;
  estimatedHours: string | null;
  mileageOneWay: string | null;
  payType: 'FIXED' | 'HOURLY' | 'BLENDED';
  payBaseAmount: string | null;
  payBaseHours: string | null;
  payHourlyRate: string | null;
  payHoursCap: string | null;
  score: string;
  recommendation: string;
  scoreReasons: string[];
  profitability: Record<string, unknown>;
};

export type FieldNationMailboxMessage = {
  messageId: string;
  sender: string | null;
  subject: string | null;
  receivedAt: Date | null;
  rawText: string;
};

const asMoney = (value: number | null) => value === null || !Number.isFinite(value) ? null : value.toFixed(2);
const asHours = (value: number | null) => value === null || !Number.isFinite(value) ? null : value.toFixed(2);

function clean(text: string): string {
  return text.replace(/\r\n?/g, '\n').replace(/\u00a0/g, ' ').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

function firstMatch(text: string, expressions: RegExp[]): string | null {
  for (const expression of expressions) {
    const match = expression.exec(text);
    if (match?.[1]) return match[1].trim();
  }
  return null;
}

function cleanCandidate(value: string | null): string | null {
  if (!value) return null;
  const cleaned = value
    .replace(/<https?:\/\/[^>]+>/gi, ' ')
    .replace(/https?:\/\/\S+/gi, ' ')
    .replace(/&(?:nbsp|amp);/gi, ' ')
    .replace(/[\u201c\u201d]/g, '')
    .replace(/^\s*["']+|["']+\s*$/g, '')
    .replace(/\s+/g, ' ')
    .trim();

  if (!cleaned || /^(?:fieldnation|toplogo\.png|view (?:work )?order|view message|unsubscribe)$/i.test(cleaned)) {
    return null;
  }

  return cleaned;
}

function estimatedHoursFromText(text: string): string | null {
  const value = firstMatch(text, [
    /\bestimated\s+(?:time|duration|hours?)\s*:?\s*([\d.]+)\s*(?:hours?|hrs?)\b/i,
    /\bestimated\s+([\d.]+)\s*(?:hours?|hrs?)\s+(?:to\s+)?complete\b/i,
    /\b([\d.]+)\s*(?:hours?|hrs?)\s+(?:estimated|to\s+complete)\b/i,
    /\b(?:onsite|on-site|service)\s+time\s*:?[ ]*([\d.]+)\s*(?:hours?|hrs?)\b/i,
  ]);
  const parsed = value === null ? null : Number(value);
  return parsed !== null && Number.isFinite(parsed) ? asHours(parsed) : null;
}

function parsePay(text: string) {
  const normalized = text.replace(/\s+/g, ' ');
  const estimatedHoursText = estimatedHoursFromText(normalized);
  const block = normalized.match(/(?:pays?\s*)?\$?\s*([\d,.]+)\s*(?:\/|for(?:\s+the)?\s+|\s+(?=(?:first|1st)\b))\s*(?:(?:first|1st)\s*)?([\d.]+)\s*(?:hours?|hrs?)\b/i);
  const hasBlendedTerms = /\b(?:pay|payment)\s+(?:type\s+)?blended\b/i.test(normalized)
    || /\bthen\s+pay\s+additional\s+hours?\b/i.test(normalized)
    || Boolean(block && /(?:then|after(?:wards)?|thereafter)/i.test(normalized.slice((block.index ?? 0) + block[0].length)));

  if (hasBlendedTerms) {
    const baseAmountText = block?.[1] ?? firstMatch(normalized, [
      /\b(?:first|base|included)\s+(?:rate|amount|pay)\s*:?\s*\$?([\d,.]+)/i,
      /\brate\s*:?\s*\$?([\d,.]+)\s+(?:first|base|included)\s+hours?\b/i,
      /\b(?:first|base|included)\s+hours?\s*:?\s*\$?([\d,.]+)/i,
    ]);
    const baseHoursText = block?.[2] ?? firstMatch(normalized, [
      /\b(?:first|base|included)\s+hours?\s*:?\s*([\d.]+)/i,
      /\brate\s*:?\s*\$?[\d,.]+\s+(?:first|base|included)\s+hours?\s*:?\s*([\d.]+)/i,
    ]);
    const additionalRateText = firstMatch(normalized, [
      /(?:then\s+pay\s+)?(?:additional|extra)\s+hours?\s+(?:rate\s*:?\s*)?\$?([\d,.]+)\s*(?:\/\s*(?:hour|hr)|per\s+(?:hour|hr))?/i,
      /\brate\s*:?\s*\$?([\d,.]+)\s*(?:\/\s*(?:hour|hr)|per\s+(?:hour|hr))\s+(?:additional|extra)\s+hours?/i,
    ]);
    const additionalHoursText = firstMatch(normalized, [
      /(?:additional|extra)\s+hours?\s*:?\s*([\d.]+)\s*(?:max|maximum)?/i,
      /(?:max(?:imum)?(?:\s+of)?|up\s+to)\s*([\d.]+)\s*(?:additional|extra)\s+hours?/i,
    ]);
    const grossText = firstMatch(normalized, [
      /\b(?:labor|total\s+estimate|advertised(?:\/max)?\s+pay|max(?:imum)?\s+pay|gross\s+pay|total\s+pay)\s*:?\s*\$?\s*([\d,.]+)/i,
      /\bpay\s*:?\s*\$?\s*([\d,.]+)\b/i,
    ]);
    const base = baseAmountText === null ? null : Number(baseAmountText.replace(/,/g, ''));
    const baseHours = baseHoursText === null ? null : Number(baseHoursText);
    const rate = additionalRateText === null ? null : Number(additionalRateText.replace(/,/g, ''));
    const hoursCap = additionalHoursText === null ? null : Number(additionalHoursText);
    if (base !== null && baseHours !== null && rate !== null && hoursCap !== null
      && Number.isFinite(base) && Number.isFinite(baseHours) && Number.isFinite(rate) && Number.isFinite(hoursCap)) {
      const gross = grossText === null ? base + rate * hoursCap : Number(grossText.replace(/,/g, ''));
      return { payType: 'BLENDED' as const, payBaseAmount: asMoney(base), payBaseHours: asHours(baseHours), payHourlyRate: asMoney(rate), payHoursCap: asHours(hoursCap), grossPay: Number.isFinite(gross) ? asMoney(gross) : asMoney(base + rate * hoursCap), estimatedHours: asHours(baseHours + hoursCap) };
    }
  }

  const hourlyRateText = firstMatch(normalized, [
    /(?:hourly\s+rate|rate)\s*:?\s*\$?([\d,.]+)\s*(?:\/\s*(?:hour|hr)|per\s+(?:hour|hr)|an?\s+hour)/i,
    /\$?\s*([\d,.]+)\s*(?:\/\s*(?:hour|hr)|per\s+(?:hour|hr))/i,
  ]);
  const maxHoursText = firstMatch(normalized, [
    /\(([\d.]+)\s+hours?\s+max\)/i,
    /(?:max(?:imum)?(?:\s+of)?|up\s+to)\s*([\d.]+)\s*(?:hours?|hrs?)/i,
  ]);
  if (hourlyRateText) {
    const rate = Number(hourlyRateText.replace(/,/g, ''));
    const maxHours = maxHoursText ? Number(maxHoursText) : null;
    if (Number.isFinite(rate)) return { payType: 'HOURLY' as const, payBaseAmount: null, payBaseHours: null, payHourlyRate: asMoney(rate), payHoursCap: asHours(maxHours), grossPay: maxHours !== null && Number.isFinite(maxHours) ? asMoney(rate * maxHours) : null, estimatedHours: asHours(maxHours) ?? estimatedHoursText };
  }
  const grossText = firstMatch(normalized, [
    /\b(?:advertised(?:\/max)?\s+pay|max(?:imum)?\s+pay|pay|fixed\s+(?:amount|rate)|to\s+be\s+paid|total\s+pay)\s*:?\s*\$\s*([\d,.]+)/i,
    /\$\s*([\d,.]+)\b/,
  ]);
  const gross = grossText ? Number(grossText.replace(/,/g, '')) : null;
  return { payType: 'FIXED' as const, payBaseAmount: asMoney(gross), payBaseHours: null, payHourlyRate: null, payHoursCap: null, grossPay: asMoney(gross), estimatedHours: maxHoursText ? asHours(Number(maxHoursText)) : estimatedHoursText };
}

function dateFromEmail(text: string, receivedAt: Date | null): Date | null {
  return parseFieldNationSchedule(text, receivedAt ?? new Date());
}

export type OpportunityVehicleCost = { vehicleId: string; vehicleName: string; rate: number; complete: boolean; model: Record<string, unknown> };
function profitability(gross: number, onsiteHours: number, oneWayMiles: number | null, oaiApplies: boolean, vehicle?: OpportunityVehicleCost) {
  const targetHourly = Number(process.env.FIELDNATION_PROFIT_TARGET_HOURLY ?? 35);
  const mileageRate = vehicle?.rate ?? Number(process.env.FIELDNATION_PROFIT_MILEAGE_RATE ?? 0.67);
  const averageMph = Number(process.env.FIELDNATION_PROFIT_AVERAGE_MPH ?? 55);
  const roundTripMiles = oneWayMiles === null ? null : oneWayMiles * 2;
  const driveMinutes = roundTripMiles === null ? null : Math.round(roundTripMiles / Math.max(5, averageMph) * 60);
  const feeSchedule = fieldNationFeeSchedule(oaiApplies);
  const feeBreakdown = calculateFieldNationFees(gross, feeSchedule.oaiApplies);
  const { platformFee, insuranceFee, oaiFee, totalFee: fees } = feeBreakdown;
  const mileageCost = roundTripMiles === null ? 0 : Math.round(roundTripMiles * mileageRate * 100) / 100;
  const estimatedNet = Math.round((gross - fees - mileageCost) * 100) / 100;
  const totalHours = onsiteHours + ((driveMinutes ?? 0) / 60);
  const effectiveHourly = totalHours > 0 ? Math.round(estimatedNet / totalHours * 100) / 100 : 0;
  const feeRate = feeSchedule.platformRate + feeSchedule.insuranceRate + (feeSchedule.oaiApplies ? feeSchedule.oaiRate : 0);
  const grossToTarget = totalHours > 0 && feeRate < 1 ? Math.round(((targetHourly * totalHours + mileageCost) / (1 - feeRate)) * 100) / 100 : 0;
  return { complete: gross > 0 && onsiteHours > 0 && roundTripMiles !== null && (vehicle?.complete ?? true), vehicle_id: vehicle?.vehicleId ?? null, vehicle_name: vehicle?.vehicleName ?? null, vehicle_cost_source: vehicle ? "VEHICLE_PROFILE" : "CONFIGURED_FALLBACK", vehicle_cost_model: vehicle?.model ?? null, gross_known: gross > 0, onsite_known: onsiteHours > 0, travel_known: roundTripMiles !== null, target_hourly: targetHourly, mileage_rate: mileageRate, average_mph: averageMph, one_way_miles: oneWayMiles, round_trip_miles: roundTripMiles, drive_minutes: driveMinutes, onsite_hours: onsiteHours, total_hours: Math.round(totalHours * 100) / 100, gross, platform_fee: platformFee, insurance_fee: insuranceFee, oai_applies: feeBreakdown.oaiApplies, oai_fee: oaiFee, fees, mileage_cost: mileageCost, estimated_net: estimatedNet, effective_hourly: effectiveHourly, counteroffer_gross: Math.max(gross, grossToTarget), counteroffer_increase: Math.max(0, Math.round((grossToTarget - gross) * 100) / 100) };
}

export function parseFieldNationOpportunityEmail(input: { subject: string; sender: string; rawText: string; receivedAt: Date | null; oaiApplies?: boolean; vehicleCost?: OpportunityVehicleCost }): FieldNationOpportunity {
  const subject = clean(input.subject);
  const body = clean(input.rawText);
  const haystack = `${subject}\n${body}`;
  const opportunityStatus = /assigned\s+to\s+someone\s+else/i.test(haystack) ? 'DECLINED' : /assigned\s+to\s+you/i.test(haystack) ? 'ASSIGNED' : /\bRouted WO\b|routed\s+work\s+order|dispatch\s+request/i.test(haystack) ? 'ROUTED' : /\bNew Work\b|\[Available Work Order\]|\bAvailable Work Order\b/i.test(haystack) ? 'AVAILABLE' : 'MESSAGE';
  const sourceReference = firstMatch(haystack, [/Work\s*Order\s*ID\s*:\s*#?\s*(\d{5,})/i, /Work\s*Order\s*#?\s*(\d{5,})/i, /WO\s*#\s*(\d{5,})/i, /\/workorders\/(\d{5,})\b/i]);
  const buyerName = cleanCandidate(firstMatch(subject, [/^(.+?)\s*[-–|]?\s*Routed WO:/i]))
    ?? cleanCandidate(firstMatch(input.sender, [/^(.+?)\s+\(Field Nation\)/i]))
    ?? cleanCandidate(firstMatch(`\n${body}`, [/\n([A-Za-z0-9 &.,'\-]+)\s*\/\s*\d+(?:\.\d+)?\b/]))
    ?? 'FieldNation';
  let title = cleanCandidate(firstMatch(body, [
    /(?:Service|Work Order|Job|Opportunity)\s+Title\s*:\s*(.+)/i,
    /^\s*New Message:\s*WO\s*#?\d+\s*\n+(.+)/im,
    /^\s*(DISPATCH REQUEST\s*-\s*[^\n]+)/im,
  ]));
  if (!title) title = cleanCandidate(firstMatch(subject, [
    /(?:New Work|Routed WO|Available Work Order|Work Order|WO)\s*#?\d*\s*[:\-–]\s*(.+)$/i,
    /^\d{5,}\s*[:\-–]\s*(.+)$/i,
  ]));
  if (!title) {
    const candidate = body.split('\n').map(line => cleanCandidate(line)).find(line => line !== null
      && line.length >= 8
      && !/^(?:hello|hi|good morning)\b.*(?:tech|technician|assist|available|complete this wo)/i.test(line)
      && !/^(Pay|Schedule|Status|Location|View Work Order|View Message|Service Details|Work Order ID)\b/i.test(line)
      && !/^[A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}\b/i.test(line)
      && !/Hourly Rate|\$\d+|\d+\s+hours?\s+max/i.test(line));
    title = candidate ?? null;
  }
  const place = firstMatch(haystack, [/Service\s+Location\s*:\s*([A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/i, /\b([A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)\s*\(\s*[\d.]+\s*(?:mi|miles)/i]);
  const distance = firstMatch(haystack, [/\b(?:Location\s+)?[A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?\s*\(\s*([\d.]+)\s*(?:mi|miles)(?:\s+away)?\s*\)/i, /\(([\d.]+)\s*(?:mi|miles)(?:\s+away)?\)/i]);
  const mileageOneWay = distance && Number.isFinite(Number(distance)) ? asHours(Number(distance)) : null;
  const pay = parsePay(haystack);
  const scheduledAt = dateFromEmail(haystack, input.receivedAt);
  const gross = Number(pay.grossPay ?? 0);
  const onsite = Number(pay.estimatedHours ?? 0);
  const profit = profitability(gross, onsite, mileageOneWay === null ? null : Number(mileageOneWay), Boolean(input.oaiApplies), input.vehicleCost);
  let score = 0;
  const scoreReasons: string[] = [];
  if (!profit.gross_known || !profit.onsite_known) { score -= 8; scoreReasons.push('-8 profitability incomplete'); }
  else if (profit.complete) {
    const hourly = Number(profit.effective_hourly);
    const target = Number(profit.target_hourly);
    const points = hourly >= target * 1.5 ? 45 : hourly >= target * 1.2 ? 38 : hourly >= target ? 30 : hourly >= target * .8 ? 18 : 5;
    score += points; scoreReasons.push(`+${points} $${hourly.toFixed(2)}/hr door-to-door`);
  } else { score += 8; scoreReasons.push('+8 partial profitability; travel unknown'); }
  const drive = profit.drive_minutes as number | null;
  const travelPoints = drive === null ? -3 : drive <= 45 ? 15 : drive <= 120 ? 8 : drive <= 180 ? -2 : -10;
  score += travelPoints; scoreReasons.push(`${travelPoints >= 0 ? '+' : ''}${travelPoints} ${drive === null ? 'travel unknown' : 'round-trip drive'}`);
  if (opportunityStatus === 'ROUTED') { score += 8; scoreReasons.push('+8 buyer routed it directly'); }
  if (/pos|cabling|cable|hme|menu|switch|tv|mount|kiosk|pinpad|drive/i.test(title ?? '')) { score += 10; scoreReasons.push('+10 preferred field work type'); }
  else if (title) { score += 3; scoreReasons.push('+3 known scope'); } else { score -= 5; scoreReasons.push('-5 unclear scope'); }
  if (scheduledAt) { if (scheduledAt.getTime() < Date.now()) { score -= 25; scoreReasons.push('-25 stale/past schedule'); } else { score += 10; scoreReasons.push('+10 future schedule present'); } } else { score -= 4; scoreReasons.push('-4 schedule unknown'); }
  scoreReasons.push(`+0 vehicle cost $${Number(profit.mileage_rate).toFixed(4)}/mi (${input.vehicleCost?.vehicleName ?? 'planning fallback'})`);
  scoreReasons.push(`+0 est net $${Number(profit.estimated_net).toFixed(2)} after $${Number(profit.fees).toFixed(2)} fees and $${Number(profit.mileage_cost).toFixed(2)} travel`);
  if (Number(profit.counteroffer_increase) > 0) scoreReasons.push(`+0 counter about $${Number(profit.counteroffer_gross).toFixed(2)} gross to target $${Number(profit.target_hourly).toFixed(2)}/hr`);
  score = Math.max(0, Math.min(100, Math.round(score)));
  const recommendation = score >= 80 ? 'Request this' : score >= 65 ? 'Worth reviewing' : score >= 45 ? 'Maybe if schedule is open' : 'Skip';
  return { sourceReference, title: cleanCandidate(title), buyerName, opportunityStatus, location: cleanCandidate(place), scheduledAt, grossPay: pay.grossPay, estimatedHours: pay.estimatedHours, mileageOneWay, payType: pay.payType, payBaseAmount: pay.payBaseAmount, payBaseHours: pay.payBaseHours, payHourlyRate: pay.payHourlyRate, payHoursCap: pay.payHoursCap, score: String(score), recommendation, scoreReasons, profitability: profit };
}

export async function readFieldNationMailbox(options: { host: string; port: number; secure: boolean; user: string; password: string; folder: string; lookbackDays: number; limit: number }): Promise<FieldNationMailboxMessage[]> {
  const client = new ImapFlow({ host: options.host, port: options.port, secure: options.secure, auth: { user: options.user, pass: options.password }, logger: false });
  const items: FieldNationMailboxMessage[] = [];
  await client.connect();
  const lock = await client.getMailboxLock(options.folder, { readOnly: true });
  try {
    const since = new Date(); since.setDate(since.getDate() - options.lookbackDays);
    const searchResult = await client.search({ since }, { uid: true });
    const uids = searchResult || [];
    for await (const message of client.fetch(uids.slice(-options.limit).reverse(), { uid: true, source: true, envelope: true }, { uid: true })) {
      const mail = await simpleParser(message.source ?? Buffer.alloc(0));
      const messageId = (mail.messageId || `fieldnation-imap-${options.folder}-${message.uid}`).trim();
      const rawText = clean(mail.text || (typeof mail.html === 'string' ? mail.html.replace(/<[^>]*>/g, ' ') : '') || '');
      if (rawText) items.push({ messageId, sender: mail.from?.text?.trim() || null, subject: mail.subject?.trim() || null, receivedAt: mail.date ?? null, rawText });
    }
  } finally { lock.release(); await client.logout(); }
  return items;
}

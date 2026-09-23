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

function parsePay(text: string) {
  const normalized = text.replace(/\s+/g, ' ');
  const blended = normalized.match(/(?:pays?\s*)?\$?\s*([\d,.]+)\s*(?:\/|for(?:\s+the)?\s+|\s+(?=(?:first|1st)\b))\s*(?:(?:first|1st)\s*)?([\d.]+)\s*(?:hours?|hrs?)\b([\s\S]{0,240}?)(?:then|after(?:wards)?|thereafter)[\s\S]{0,120}?(?:at\s*)?\$?\s*([\d,.]+)\s*(?:\/\s*(?:hour|hr)|per\s+(?:hour|hr))/i);
  if (blended) {
    const base = Number(blended[1].replace(/,/g, ''));
    const baseHours = Number(blended[2]);
    const tail = `${blended[3]} ${normalized.slice((blended.index ?? 0) + blended[0].length)}`;
    const cap = firstMatch(tail, [/(?:max(?:imum)?(?:\s+of)?|up\s+to)\s*([\d.]+)\s*(?:additional\s*)?(?:hours?|hrs?)/i]);
    const rate = Number(blended[4].replace(/,/g, ''));
    if (Number.isFinite(base) && Number.isFinite(baseHours) && Number.isFinite(rate) && cap && Number.isFinite(Number(cap))) {
      const hoursCap = Number(cap);
      return { payType: 'BLENDED' as const, payBaseAmount: asMoney(base), payBaseHours: asHours(baseHours), payHourlyRate: asMoney(rate), payHoursCap: asHours(hoursCap), grossPay: asMoney(base + rate * hoursCap), estimatedHours: asHours(baseHours + hoursCap) };
    }
  }
  const hourlyRateText = firstMatch(normalized, [/Hourly\s+Rate\s*:\s*\$?([\d,.]+)\s*\/\s*(?:hour|hr)/i, /\$?\s*([\d,.]+)\s*(?:\/\s*(?:hour|hr)|per\s+(?:hour|hr))/i]);
  const maxHoursText = firstMatch(normalized, [/\(([\d.]+)\s+hours?\s+max\)/i, /(?:max(?:imum)?(?:\s+of)?|up\s+to)\s*([\d.]+)\s*(?:hours?|hrs?)/i]);
  if (hourlyRateText) {
    const rate = Number(hourlyRateText.replace(/,/g, ''));
    const maxHours = maxHoursText ? Number(maxHoursText) : null;
    if (Number.isFinite(rate)) return { payType: 'HOURLY' as const, payBaseAmount: null, payBaseHours: null, payHourlyRate: asMoney(rate), payHoursCap: asHours(maxHours), grossPay: maxHours !== null && Number.isFinite(maxHours) ? asMoney(rate * maxHours) : null, estimatedHours: asHours(maxHours) };
  }
  const grossText = firstMatch(normalized, [/\b(?:Pay|Fixed Rate|To be paid|Total Pay)\s*:?\s*\$\s*([\d,.]+)/i, /\$\s*([\d,.]+)\b/]);
  const gross = grossText ? Number(grossText.replace(/,/g, '')) : null;
  return { payType: 'FIXED' as const, payBaseAmount: asMoney(gross), payBaseHours: null, payHourlyRate: null, payHoursCap: null, grossPay: asMoney(gross), estimatedHours: maxHoursText ? asHours(Number(maxHoursText)) : null };
}

function dateFromEmail(text: string, receivedAt: Date | null): Date | null {
  const year = receivedAt?.getUTCFullYear() ?? new Date().getUTCFullYear();
  const range = text.match(/(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s*([A-Za-z]{3,9})\s+(\d{1,2}),?\s*(?:@\s*)?(\d{1,2}(?::\d{2})?\s*(?:AM|PM))/i)
    ?? text.match(/\b([A-Za-z]{3,9})\s+(\d{1,2})\s+(\d{1,2}:\d{2}\s*(?:AM|PM))/i);
  if (!range) return null;
  const month = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].indexOf(range[1].slice(0, 3).toLowerCase());
  const day = Number(range[2]);
  const time = range[3].match(/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i);
  if (month < 0 || !time) return null;
  let hour = Number(time[1]) % 12;
  if (time[3].toUpperCase() === 'PM') hour += 12;
  const local = new Date(year, month, day, hour, Number(time[2] ?? '0'));
  return Number.isNaN(local.getTime()) ? null : local;
}

function profitability(gross: number, onsiteHours: number, oneWayMiles: number | null, oaiApplies: boolean) {
  const targetHourly = Number(process.env.FIELDNATION_PROFIT_TARGET_HOURLY ?? 35);
  const mileageRate = Number(process.env.FIELDNATION_PROFIT_MILEAGE_RATE ?? 0.67);
  const averageMph = Number(process.env.FIELDNATION_PROFIT_AVERAGE_MPH ?? 55);
  const insuranceRate = Number(process.env.FIELDNATION_INSURANCE_FEE_RATE ?? 0.0195);
  const oaiRate = Number(process.env.FIELDNATION_OAI_FEE_RATE ?? 0.005);
  const roundTripMiles = oneWayMiles === null ? null : oneWayMiles * 2;
  const driveMinutes = roundTripMiles === null ? null : Math.round(roundTripMiles / Math.max(5, averageMph) * 60);
  const platformFee = Math.round(gross * 0.10 * 100) / 100;
  const insuranceFee = Math.round(gross * insuranceRate * 100) / 100;
  const oaiFee = oaiApplies ? Math.round(gross * oaiRate * 100) / 100 : 0;
  const mileageCost = roundTripMiles === null ? 0 : Math.round(roundTripMiles * mileageRate * 100) / 100;
  const fees = Math.round((platformFee + insuranceFee + oaiFee) * 100) / 100;
  const estimatedNet = Math.round((gross - fees - mileageCost) * 100) / 100;
  const totalHours = onsiteHours + ((driveMinutes ?? 0) / 60);
  const effectiveHourly = totalHours > 0 ? Math.round(estimatedNet / totalHours * 100) / 100 : 0;
  const feeRate = .10 + insuranceRate + (oaiApplies ? oaiRate : 0);
  const grossToTarget = totalHours > 0 && feeRate < 1 ? Math.round(((targetHourly * totalHours + mileageCost) / (1 - feeRate)) * 100) / 100 : 0;
  return { complete: gross > 0 && onsiteHours > 0 && roundTripMiles !== null, gross_known: gross > 0, onsite_known: onsiteHours > 0, travel_known: roundTripMiles !== null, target_hourly: targetHourly, mileage_rate: mileageRate, average_mph: averageMph, one_way_miles: oneWayMiles, round_trip_miles: roundTripMiles, drive_minutes: driveMinutes, onsite_hours: onsiteHours, total_hours: Math.round(totalHours * 100) / 100, gross, platform_fee: platformFee, insurance_fee: insuranceFee, oai_applies: oaiApplies, oai_fee: oaiFee, fees, mileage_cost: mileageCost, estimated_net: estimatedNet, effective_hourly: effectiveHourly, counteroffer_gross: Math.max(gross, grossToTarget), counteroffer_increase: Math.max(0, Math.round((grossToTarget - gross) * 100) / 100) };
}

export function parseFieldNationOpportunityEmail(input: { subject: string; sender: string; rawText: string; receivedAt: Date | null; oaiApplies?: boolean }): FieldNationOpportunity {
  const subject = clean(input.subject);
  const body = clean(input.rawText);
  const haystack = `${subject}\n${body}`;
  const opportunityStatus = /assigned\s+to\s+someone\s+else/i.test(haystack) ? 'DECLINED' : /assigned\s+to\s+you/i.test(haystack) ? 'ASSIGNED' : /\bRouted WO\b|routed\s+work\s+order|dispatch\s+request/i.test(haystack) ? 'ROUTED' : /\bNew Work\b|\[Available Work Order\]|\bAvailable Work Order\b/i.test(haystack) ? 'AVAILABLE' : 'MESSAGE';
  const sourceReference = firstMatch(haystack, [/Work\s*Order\s*ID\s*:\s*#?\s*(\d{5,})/i, /Work\s*Order\s*#?\s*(\d{5,})/i, /WO\s*#\s*(\d{5,})/i, /\/workorders\/(\d{5,})\b/i]);
  const buyerName = firstMatch(subject, [/^(.+?)\s+Routed WO:/i]) ?? firstMatch(input.sender, [/^(.+?)\s+\(Field Nation\)/i]) ?? firstMatch(`\n${body}`, [/\n([A-Za-z0-9 &.,'\-]+)\s*\/\s*\d+(?:\.\d+)?\b/]) ?? 'FieldNation';
  let title = firstMatch(body, [/Service\s+Title\s*:\s*(.+)/i, /^\s*New Message:\s*WO\s*#?\d+\s*\n+(.+)/im, /^\s*(DISPATCH REQUEST\s*-\s*[^\n]+)/im]);
  if (!title) title = firstMatch(subject, [/^(?:New Work|Routed WO):\s*(.+)$/i]);
  if (!title) {
    title = body.split('\n').map(line => line.trim()).find(line => line.length >= 8 && !/^(Pay|Schedule|Status|Location|View Work Order|View Message|Service Details|Work Order ID)\b/i.test(line) && !/^[A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}\b/i.test(line) && !/Hourly Rate|\$\d+|\d+\s+hours?\s+max/i.test(line)) ?? null;
  }
  const place = firstMatch(haystack, [/Service\s+Location\s*:\s*([A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/i, /\b([A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)\s*\(\s*[\d.]+\s*(?:mi|miles)/i]);
  const distance = firstMatch(haystack, [/\b(?:Location\s+)?[A-Za-z .'-]+,\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?\s*\(\s*([\d.]+)\s*(?:mi|miles)(?:\s+away)?\s*\)/i, /\(([\d.]+)\s*(?:mi|miles)(?:\s+away)?\)/i]);
  const mileageOneWay = distance && Number.isFinite(Number(distance)) ? asHours(Number(distance)) : null;
  const pay = parsePay(haystack);
  const scheduledAt = dateFromEmail(haystack, input.receivedAt);
  const gross = Number(pay.grossPay ?? 0);
  const onsite = Number(pay.estimatedHours ?? 0);
  const profit = profitability(gross, onsite, mileageOneWay === null ? null : Number(mileageOneWay), Boolean(input.oaiApplies));
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
  scoreReasons.push(`+0 est net $${Number(profit.estimated_net).toFixed(2)} after $${Number(profit.fees).toFixed(2)} fees and $${Number(profit.mileage_cost).toFixed(2)} travel`);
  if (Number(profit.counteroffer_increase) > 0) scoreReasons.push(`+0 counter about $${Number(profit.counteroffer_gross).toFixed(2)} gross to target $${Number(profit.target_hourly).toFixed(2)}/hr`);
  score = Math.max(0, Math.min(100, Math.round(score)));
  const recommendation = score >= 80 ? 'Request this' : score >= 65 ? 'Worth reviewing' : score >= 45 ? 'Maybe if schedule is open' : 'Skip';
  return { sourceReference, title: title?.replace(/\s+/g, ' ').trim() || null, buyerName, opportunityStatus, location: place, scheduledAt, grossPay: pay.grossPay, estimatedHours: pay.estimatedHours, mileageOneWay, payType: pay.payType, payBaseAmount: pay.payBaseAmount, payBaseHours: pay.payBaseHours, payHourlyRate: pay.payHourlyRate, payHoursCap: pay.payHoursCap, score: String(score), recommendation, scoreReasons, profitability: profit };
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

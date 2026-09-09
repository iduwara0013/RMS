'use client';

type DateRangeFilterProps = {
  fromDate: string;
  toDate: string;
  dateLabel: string;
  onFromDateChange: (value: string) => void;
  onToDateChange: (value: string) => void;
};

export function matchesDateRange(value: string | null | undefined, fromDate: string, toDate: string) {
  if (!fromDate && !toDate) return true;
  if (!value) return false;
  const recordDate = value.slice(0, 10);
  return (!fromDate || recordDate >= fromDate) && (!toDate || recordDate <= toDate);
}

export default function DateRangeFilter({ fromDate, toDate, dateLabel, onFromDateChange, onToDateChange }: DateRangeFilterProps) {
  const hasSelection = Boolean(fromDate || toDate);

  return <div className="record-date-filter">
    <div className="record-date-filter__copy"><strong>Filter by {dateLabel}</strong><span>Choose either date or a complete range.</span></div>
    <label><span>From date</span><input type="date" value={fromDate} max={toDate || undefined} onChange={(event) => onFromDateChange(event.target.value)} /></label>
    <label><span>To date</span><input type="date" value={toDate} min={fromDate || undefined} onChange={(event) => onToDateChange(event.target.value)} /></label>
    <button type="button" disabled={!hasSelection} onClick={() => { onFromDateChange(''); onToDateChange(''); }}>Clear dates</button>
  </div>;
}

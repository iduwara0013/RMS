export function splitVacancyDescription(description: string) {
  const normalized = description.replace(/\r/g, '').trim();
  const parts = normalized.split(/\brequirements?\s*:\s*/i);
  const overview = parts[0].replace(/\s*[•·]\s*$/, '').trim();
  const requirements = parts.slice(1).join(' ').split(/\s*[•·]\s*|\n+/).map((item) => item.trim()).filter(Boolean);

  return { overview, requirements };
}

export const loadCalendar = async () => {
  await import('temporal-polyfill/global');
  const [{ Calendar }, { default: dayGridPlugin }, { default: interactionPlugin }, _styles] = await Promise.all([
    import('fullcalendar'),
    import('fullcalendar/daygrid'),
    import('fullcalendar/interaction'),
    import('fullcalendar/skeleton.css'),
  ]);

  return { Calendar, dayGridPlugin, interactionPlugin };
};

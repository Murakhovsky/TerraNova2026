export const loadDatePicker = async () => {
  const [module, _styles] = await Promise.all([
    import('flatpickr'),
    import('flatpickr/dist/flatpickr.min.css'),
  ]);

  return module.default;
};

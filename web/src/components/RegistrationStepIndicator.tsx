interface RegistrationStepIndicatorProps {
  steps: string[]
  currentStep: number
}

export function RegistrationStepIndicator({ steps, currentStep }: RegistrationStepIndicatorProps) {
  return (
    <div className="mb-7" aria-label={`Step ${currentStep + 1} of ${steps.length}: ${steps[currentStep]}`}>
      <div className="mb-3 flex items-center justify-between gap-3 text-xs font-bold uppercase tracking-[0.16em]">
        <span className="text-neon-green">Step {currentStep + 1} of {steps.length}</span>
        <span className="text-white/55">{steps[currentStep]}</span>
      </div>
      <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }}>
        {steps.map((step, index) => (
          <span
            key={step}
            aria-hidden="true"
            className={`h-1.5 rounded-full transition-colors ${index <= currentStep ? 'bg-neon-green shadow-[0_0_8px_rgba(57,255,20,0.45)]' : 'bg-white/10'}`}
          />
        ))}
      </div>
    </div>
  )
}

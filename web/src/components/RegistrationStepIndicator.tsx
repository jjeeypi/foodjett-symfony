interface RegistrationStepIndicatorProps { steps: string[]; currentStep: number }
export function RegistrationStepIndicator({ steps, currentStep }: RegistrationStepIndicatorProps) {
  return <div className="fj-auth-progress" aria-label={`Step ${currentStep + 1} of ${steps.length}: ${steps[currentStep]}`}><div><span>Step {currentStep + 1} of {steps.length}</span><strong>{steps[currentStep]}</strong></div><ol>{steps.map((step, index) => <li key={step} aria-hidden="true" className={index <= currentStep ? 'is-complete' : ''}><i />{index < steps.length - 1 && <span />}</li>)}</ol></div>
}

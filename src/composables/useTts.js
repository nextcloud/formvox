import { ref } from 'vue';

export function useTts() {
	const speakingQuestionId = ref(null);

	const isSupported = typeof window !== 'undefined'
		&& 'speechSynthesis' in window;

	function buildSpeechText(question, renderedQuestion, renderedDescription, renderPiping) {
		const parts = [renderedQuestion];

		if (renderedDescription) {
			parts.push(renderedDescription);
		}

		if (['choice', 'multiple', 'dropdown'].includes(question.type) && question.options) {
			const optionLabels = question.options
				.map(o => renderPiping ? renderPiping(o.label) : o.label);
			parts.push(optionLabels.join(', '));
		}

		if (question.type === 'scale') {
			const min = question.scaleMin || 1;
			const max = question.scaleMax || 5;
			let scaleText = `${min} - ${max}`;
			if (question.scaleMinLabel) scaleText += `, ${question.scaleMinLabel}`;
			if (question.scaleMaxLabel) scaleText += ` - ${question.scaleMaxLabel}`;
			parts.push(scaleText);
		}

		if (question.type === 'rating') {
			parts.push(`1 - ${question.ratingMax || 5}`);
		}

		if (question.type === 'matrix' && question.rows && question.columns) {
			const rowLabels = question.rows.map(r => r.label).join(', ');
			const colLabels = question.columns.map(c => c.label).join(', ');
			parts.push(`${rowLabels}. ${colLabels}`);
		}

		return parts.join('. ');
	}

	function speak(questionId, text) {
		if (!isSupported) return;

		if (speakingQuestionId.value === questionId) {
			stop();
			return;
		}

		window.speechSynthesis.cancel();

		const utterance = new SpeechSynthesisUtterance(text);
		utterance.lang = document.documentElement.lang || navigator.language || 'en';

		utterance.onend = () => {
			speakingQuestionId.value = null;
		};
		utterance.onerror = () => {
			speakingQuestionId.value = null;
		};

		speakingQuestionId.value = questionId;
		window.speechSynthesis.speak(utterance);
	}

	function stop() {
		if (!isSupported) return;
		window.speechSynthesis.cancel();
		speakingQuestionId.value = null;
	}

	return {
		isSupported,
		speakingQuestionId,
		buildSpeechText,
		speak,
		stop,
	};
}
